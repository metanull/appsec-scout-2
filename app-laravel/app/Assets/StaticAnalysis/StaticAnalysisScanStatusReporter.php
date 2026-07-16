<?php

declare(strict_types=1);

namespace App\Assets\StaticAnalysis;

use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Sources\AzDo\AzDoNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\Filesystem;

/**
 * Read-only status for every static-analysis-scan run currently on disk, shared by the
 * `staticanalysis:scan-status` command and the Filament Operations page so both report
 * exactly the same thing. Derives everything from artifacts that already exist
 * (run.jsonl, summary.json, the .processed cursor, static-analysis-import ErrorLog rows) —
 * no new persisted state.
 *
 * Mirrors SbomScanStatusReporter — see that class for the derivation rationale, which
 * applies identically here.
 */
final class StaticAnalysisScanStatusReporter
{
    public function __construct(private readonly Filesystem $files) {}

    /** @return list<array{run: string, dryRun: bool, finished: bool, imported: int, total: ?int, totalIsApprox: bool, failed: int, lastUpdated: ?CarbonImmutable}> */
    public function statusForAllRuns(): array
    {
        $importBase = (string) config('static_analysis.import_path');

        if (! $this->files->isDirectory($importBase)) {
            return [];
        }

        $approxTotal = $this->approxKnownRepoCount();
        $failuresByRun = $this->failureCountsByRun();

        $rows = [];

        foreach ($this->files->directories($importBase) as $runDir) {
            $runName = basename($runDir);
            $rows[] = $this->statusForRun($runDir, $runName, $approxTotal, $failuresByRun[$runName] ?? 0);
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($b['run'], $a['run']));

        return $rows;
    }

    /** @return array{run: string, dryRun: bool, finished: bool, imported: int, total: ?int, totalIsApprox: bool, failed: int, lastUpdated: ?CarbonImmutable} */
    private function statusForRun(string $runDir, string $runName, ?int $approxTotal, int $failed): array
    {
        $dryRun = $this->files->isFile($runDir . '/.skip-import');
        $summaryPath = $runDir . '/summary.json';
        $finished = $this->files->isFile($summaryPath);

        $linesSeen = $this->countLines($runDir . '/run.jsonl');

        $total = $approxTotal;
        $totalIsApprox = true;

        if ($finished) {
            /** @var array<string, mixed>|null $summary */
            $summary = json_decode($this->files->get($summaryPath), true);
            $total = is_array($summary) && isset($summary['repositoriesConsidered']) ? (int) $summary['repositoriesConsidered'] : $linesSeen;
            $totalIsApprox = false;
        } elseif ($total !== null) {
            // Never claim fewer repos than this run has already literally logged.
            $total = max($total, $linesSeen);
        }

        $cursorFile = (string) config('static_analysis.cursor_path') . '/' . $runName . '.processed';
        $imported = $this->files->isFile($cursorFile) ? (int) trim($this->files->get($cursorFile)) : 0;
        $lastUpdated = $this->files->isFile($cursorFile)
            ? CarbonImmutable::createFromTimestamp($this->files->lastModified($cursorFile))
            : null;

        return [
            'run' => $runName,
            'dryRun' => $dryRun,
            'finished' => $finished,
            'imported' => $imported,
            'total' => $total,
            'totalIsApprox' => $totalIsApprox,
            'failed' => $failed,
            'lastUpdated' => $lastUpdated,
        ];
    }

    private function countLines(string $resultsFile): int
    {
        if (! $this->files->isFile($resultsFile)) {
            return 0;
        }

        $rawLines = preg_split('/\R/', rtrim($this->files->get($resultsFile), "\r\n"));

        return count(array_filter($rawLines === false ? [] : $rawLines, static fn (string $line): bool => $line !== ''));
    }

    /** Grows over time as scans/syncs discover the org's repos — a genuine, improving approximation, not a guess. */
    private function approxKnownRepoCount(): ?int
    {
        $count = SecurityContainer::query()
            ->whereHas('softwareSystem', function (Builder $query): void {
                /** @var Builder<SoftwareSystem> $query */
                $query->where('source_id', AzDoNormalizer::SOURCE_ID);
            })
            ->where('kind', 'repository')
            ->count();

        return $count > 0 ? $count : null;
    }

    /** @return array<string, int> */
    private function failureCountsByRun(): array
    {
        return ErrorLog::query()
            ->where('channel', 'static-analysis-import')
            ->get(['context_json'])
            ->countBy(fn (ErrorLog $error): string => (string) data_get($error->context_json, 'run'))
            ->all();
    }
}
