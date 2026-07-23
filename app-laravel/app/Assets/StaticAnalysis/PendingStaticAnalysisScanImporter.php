<?php

declare(strict_types=1);

namespace App\Assets\StaticAnalysis;

use App\Assets\AttachmentService;
use App\Assets\AttachmentTargetResolver;
use App\Assets\AzDoScanResultDtoFactory;
use App\Models\ErrorLog;
use App\Sources\AzDo\AzDoNormalizer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Throwable;

/**
 * Imports Roslynator/SpotBugs SARIF reports from an in-progress or finished
 * `invoke-ops.ps1 -StaticAnalysis` run as soon as each repository's line lands in
 * run.jsonl, instead of waiting for the whole (multi-hour) scan to finish. Scheduled
 * to run every minute; invoke-ops.ps1 also calls the
 * `staticanalysis:import-pending-scans` command once directly right after the scan
 * container exits, to flush anything the last scheduled tick hasn't picked up yet.
 *
 * Mirrors PendingSbomScanImporter — see that class for the cursor/idempotency/failure
 * handling rationale, which applies identically here.
 */
final class PendingStaticAnalysisScanImporter
{
    /** @var list<array{generated: string, path: string, kind: string}> */
    private const REPORT_KINDS = [
        ['generated' => 'dotnetAnalysisGenerated', 'path' => 'dotnetAnalysisPath', 'kind' => 'code-quality-dotnet'],
        ['generated' => 'javaAnalysisGenerated', 'path' => 'javaAnalysisPath', 'kind' => 'code-quality-java'],
    ];

    public function __construct(
        private readonly AttachmentTargetResolver $resolver,
        private readonly AttachmentService $attachments,
        private readonly Filesystem $files,
    ) {}

    /** @return array{runsSeen: int, linesImported: int, reportsImported: int, reportsFailed: int, aborted: bool} */
    public function importPending(): array
    {
        $stats = ['runsSeen' => 0, 'linesImported' => 0, 'reportsImported' => 0, 'reportsFailed' => 0, 'aborted' => false];

        $this->pruneOrphanedCursors();

        if (! $this->infrastructureIsHealthy()) {
            $stats['aborted'] = true;

            return $stats;
        }

        $importBase = (string) config('static_analysis.import_path');
        $cursorDir = (string) config('static_analysis.cursor_path');

        if (! $this->files->isDirectory($importBase)) {
            return $stats;
        }

        $this->files->ensureDirectoryExists($cursorDir);

        foreach ($this->files->directories($importBase) as $runDir) {
            $runName = basename($runDir);
            $resultsFile = $runDir . '/run.jsonl';

            // Written by collect-static-analysis.sh when invoke-ops.ps1 -SkipUpload started
            // this scan — a dry run must never reach appsec-scout, including via this
            // scheduled tick, which otherwise runs independently of that script.
            if ($this->files->isFile($runDir . '/.skip-import')) {
                continue;
            }

            if (! $this->files->isFile($resultsFile)) {
                continue;
            }

            $stats['runsSeen']++;

            if (! $this->importRun($runName, $resultsFile, $cursorDir, $stats)) {
                $stats['aborted'] = true;

                return $stats;
            }
        }

        return $stats;
    }

    /**
     * Pure filesystem cleanup, independent of database/queue health, so it still runs
     * even during an aborted tick — deletes a ".processed" cursor once its run
     * directory no longer exists under the static analysis output mount.
     */
    private function pruneOrphanedCursors(): void
    {
        $importBase = (string) config('static_analysis.import_path');
        $cursorDir = (string) config('static_analysis.cursor_path');

        if (! $this->files->isDirectory($cursorDir)) {
            return;
        }

        foreach ($this->files->files($cursorDir) as $cursorFile) {
            $path = (string) $cursorFile;

            if (! str_ends_with($path, '.processed')) {
                continue;
            }

            $runName = basename($path, '.processed');

            if (! $this->files->isDirectory($importBase . '/' . $runName)) {
                $this->files->delete($path);
            }
        }
    }

    /**
     * Checked once, up front, rather than inferred from whichever call happens to hit
     * the dead dependency first — a sustained outage aborts cleanly with zero side
     * effects instead of silently skipping every line it touches along the way.
     */
    private function infrastructureIsHealthy(): bool
    {
        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            $this->logToFile('Aborting staticanalysis:import-pending-scans: database is unreachable.', $exception);

            return false;
        }

        try {
            Queue::connection()->size();
        } catch (Throwable $exception) {
            $this->logToFile('Aborting staticanalysis:import-pending-scans: queue backend is unreachable.', $exception);

            return false;
        }

        return true;
    }

    /**
     * @param  array{runsSeen: int, linesImported: int, reportsImported: int, reportsFailed: int, aborted: bool}  $stats
     * @return bool false if the run should be aborted (infrastructure failure) rather than continuing
     */
    private function importRun(string $runName, string $resultsFile, string $cursorDir, array &$stats): bool
    {
        $cursorFile = $cursorDir . '/' . $runName . '.processed';
        $alreadyProcessed = $this->readCursor($cursorFile);

        $rawLines = preg_split('/\R/', rtrim($this->files->get($resultsFile), "\r\n"));
        $lines = array_values(array_filter($rawLines === false ? [] : $rawLines, static fn (string $line): bool => $line !== ''));

        for ($i = $alreadyProcessed; $i < count($lines); $i++) {
            /** @var array<string, mixed> $result */
            $result = json_decode($lines[$i], true, 512, JSON_THROW_ON_ERROR);

            foreach (self::REPORT_KINDS as $reportKind) {
                if (($result[$reportKind['generated']] ?? false) !== true) {
                    continue;
                }

                $relativePath = $result[$reportKind['path']] ?? null;

                if (! is_string($relativePath) || $relativePath === '') {
                    continue;
                }

                $path = (string) config('static_analysis.import_path') . '/' . $runName . '/' . $relativePath;

                if (! $this->files->isFile($path)) {
                    // Permanent, content-level failure — the file will never appear on
                    // its own, so log it and keep going rather than blocking the run.
                    $stats['reportsFailed']++;
                    $this->logFailure($runName, $result, $reportKind['kind'], new InvalidArgumentException("Report file not found: {$path}"));

                    continue;
                }

                try {
                    $this->importReport($result, $reportKind['kind'], $path, $relativePath);
                    $stats['reportsImported']++;
                } catch (Throwable $exception) {
                    // Anything else (database/queue failure mid-run, etc.) is treated as
                    // infrastructure trouble, not a content problem: abort without
                    // advancing the cursor so this exact line is retried next tick.
                    $this->logToFile("Aborting staticanalysis:import-pending-scans while importing {$runName} line " . ($i + 1) . '.', $exception);

                    return false;
                }
            }

            $stats['linesImported']++;
            $this->writeCursor($cursorFile, $i + 1);
        }

        return true;
    }

    /** @param array<string, mixed> $result */
    private function importReport(array $result, string $kind, string $path, string $relativePath): void
    {
        $projectId = (string) ($result['projectId'] ?? '');
        $repositoryId = (string) ($result['repositoryId'] ?? '');
        $project = (string) ($result['project'] ?? '');
        $repository = (string) ($result['repository'] ?? '');

        // Normalize through the same path an AzDO source sync uses, so a row this
        // import creates holds the same description, web URLs and SourceContextFacts
        // a sync produces. The resolver applies this enrichment on create only.
        $system = AzDoScanResultDtoFactory::system($result);
        $container = AzDoScanResultDtoFactory::container($result);

        $owner = $this->resolver->resolveSystem(
            AzDoNormalizer::SOURCE_ID,
            $projectId,
            $project,
            url: $system->url,
            description: $system->description,
            metadata: $system->metadata ?? [],
        );
        $owner = $this->resolver->resolveContainer(
            $owner,
            $repositoryId,
            $repository,
            'repository',
            url: $container->url,
            metadata: $container->metadata ?? [],
        );

        $this->attachments->attachTo(
            owner: $owner,
            kind: $kind,
            mime: 'application/octet-stream',
            name: basename($relativePath),
            payload: $this->files->get($path),
            createdByCommand: 'staticanalysis:import-pending-scans',
        );
    }

    /** @param array<string, mixed> $result */
    private function logFailure(string $runName, array $result, string $kind, Throwable $exception): void
    {
        try {
            ErrorLog::query()->create([
                'level' => 'error',
                'channel' => 'static-analysis-import',
                'message' => $exception->getMessage(),
                'context_json' => [
                    'run' => $runName,
                    'project' => $result['project'] ?? null,
                    'repository' => $result['repository'] ?? null,
                    'kind' => $kind,
                ],
                'trace' => $exception->getTraceAsString(),
                'occurred_at' => now(),
            ]);
        } catch (Throwable $secondaryException) {
            // The database is presumed reachable at this point (infrastructureIsHealthy
            // already checked it), but don't let a failure to *log* a content-level
            // failure crash the whole run — fall back to the file-based log instead.
            $this->logToFile(
                "Could not record static-analysis-import failure to ErrorLog for {$runName} ({$kind}): {$exception->getMessage()}",
                $secondaryException,
            );
        }
    }

    private function logToFile(string $message, Throwable $exception): void
    {
        Log::channel('single')->error($message, ['exception' => $exception]);
    }

    private function readCursor(string $cursorFile): int
    {
        if (! $this->files->isFile($cursorFile)) {
            return 0;
        }

        return (int) trim($this->files->get($cursorFile));
    }

    private function writeCursor(string $cursorFile, int $lineCount): void
    {
        $this->files->put($cursorFile, (string) $lineCount);
    }
}
