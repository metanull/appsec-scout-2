<?php

declare(strict_types=1);

namespace App\Assets\Sbom;

use App\Assets\AttachmentService;
use App\Assets\AttachmentTargetResolver;
use App\Models\ErrorLog;
use App\Sources\AzDo\AzDoNormalizer;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Throwable;

/**
 * Imports SBOM/vulnerability/secret reports from an in-progress or finished
 * `invoke-ops.ps1 -Mode sbom-scan` run as soon as each repository's line lands in
 * run.jsonl, instead of waiting for the whole (multi-hour) scan to finish. Scheduled
 * every minute alongside integrations:dispatch-due; invoke-ops.ps1 also calls the
 * `sbom:import-pending-scans` command once directly right after the scan container
 * exits, to flush anything the last scheduled tick hasn't picked up yet.
 *
 * A per-run ".processed" cursor file (line count already imported) makes repeated
 * runs safe: only lines appended since the last read get imported. A report import
 * failure is logged to ErrorLog and does not block the rest of the line/run — it is
 * not retried automatically, so it must be re-imported manually via
 * assets:import-attachment if needed.
 */
final class PendingSbomScanImporter
{
    /** @var list<array{generated: string, path: string, kind: string}> */
    private const REPORT_KINDS = [
        ['generated' => 'sbomGenerated', 'path' => 'sbomPath', 'kind' => 'sbom'],
        ['generated' => 'vulnerabilitiesGenerated', 'path' => 'vulnerabilitiesPath', 'kind' => 'vulnerabilities'],
        ['generated' => 'secretsGenerated', 'path' => 'secretsPath', 'kind' => 'secrets'],
    ];

    public function __construct(
        private readonly AttachmentTargetResolver $resolver,
        private readonly AttachmentService $attachments,
        private readonly Filesystem $files,
    ) {}

    /** @return array{runsSeen: int, linesImported: int, reportsImported: int, reportsFailed: int} */
    public function importPending(): array
    {
        $importBase = (string) config('sbom.import_path');
        $cursorDir = (string) config('sbom.cursor_path');

        $stats = ['runsSeen' => 0, 'linesImported' => 0, 'reportsImported' => 0, 'reportsFailed' => 0];

        if (! $this->files->isDirectory($importBase)) {
            return $stats;
        }

        $this->files->ensureDirectoryExists($cursorDir);

        foreach ($this->files->directories($importBase) as $runDir) {
            $runName = basename($runDir);
            $resultsFile = $runDir . '/run.jsonl';

            // Written by collect-sboms.sh when invoke-ops.ps1 -SkipUpload started this
            // scan — a dry run must never reach appsec-scout, including via this
            // scheduled tick, which otherwise runs independently of that script.
            if ($this->files->isFile($runDir . '/.skip-import')) {
                continue;
            }

            if (! $this->files->isFile($resultsFile)) {
                continue;
            }

            $stats['runsSeen']++;
            $this->importRun($runName, $resultsFile, $cursorDir, $stats);
        }

        return $stats;
    }

    /** @param array{runsSeen: int, linesImported: int, reportsImported: int, reportsFailed: int} $stats */
    private function importRun(string $runName, string $resultsFile, string $cursorDir, array &$stats): void
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

                try {
                    $this->importReport($runName, $result, $reportKind['kind'], $relativePath);
                    $stats['reportsImported']++;
                } catch (Throwable $exception) {
                    $stats['reportsFailed']++;
                    $this->logFailure($runName, $result, $reportKind['kind'], $exception);
                }
            }

            $stats['linesImported']++;
            $this->writeCursor($cursorFile, $i + 1);
        }
    }

    /** @param array<string, mixed> $result */
    private function importReport(string $runName, array $result, string $kind, string $relativePath): void
    {
        $path = (string) config('sbom.import_path') . '/' . $runName . '/' . $relativePath;

        if (! $this->files->isFile($path)) {
            throw new InvalidArgumentException("Report file not found: {$path}");
        }

        $projectId = (string) ($result['projectId'] ?? '');
        $repositoryId = (string) ($result['repositoryId'] ?? '');
        $project = (string) ($result['project'] ?? '');
        $repository = (string) ($result['repository'] ?? '');

        $owner = $this->resolver->resolveSystem(AzDoNormalizer::SOURCE_ID, $projectId, $project);
        $owner = $this->resolver->resolveContainer($owner, $repositoryId, $repository, 'repository');

        $this->attachments->attachTo(
            owner: $owner,
            kind: $kind,
            mime: 'application/octet-stream',
            name: basename($relativePath),
            payload: $this->files->get($path),
            createdByCommand: 'sbom:import-pending-scans',
        );
    }

    /** @param array<string, mixed> $result */
    private function logFailure(string $runName, array $result, string $kind, Throwable $exception): void
    {
        ErrorLog::query()->create([
            'level' => 'error',
            'channel' => 'sbom-import',
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
