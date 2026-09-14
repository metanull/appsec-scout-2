<?php

namespace App\Assets;

use App\Assets\Parsers\SarifArtifactUriNormalizer;
use App\Audit\Recorder;
use App\Models\LocalFinding;
use App\Models\LocalFindingComment;
use App\Models\LocalFindingWorkItemLink;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair for `local_findings` rows stored before
 * `App\Assets\Parsers\SarifArtifactUriNormalizer` existed, whose `file_path` is an
 * absolute container path (`file:///workspace-scratch/<uuid>/work/...` from the
 * in-app collector, `file:///tmp/tmp.XXXXXXXXXX/...` from the shell collector)
 * instead of a repository-relative one. Rewrites `file_path`/`dedup_hash` in place,
 * or — when a clean, already-relative twin already exists for the same identity —
 * merges the stale row's comments and work-item links onto the twin and deletes the
 * stale row, so re-running the fixed collectors never leaves an operator with two
 * rows for the same finding.
 */
final class LocalFindingFilePathRepairer
{
    public function __construct(
        private readonly SarifArtifactUriNormalizer $normalizer,
        private readonly Recorder $recorder,
    ) {}

    /**
     * @return array{repaired: int, merged: int, skipped: list<array{id: int, file_path: string}>}
     */
    public function repair(bool $dryRun = false): array
    {
        $repaired = 0;
        $merged = 0;
        $skipped = [];

        LocalFinding::query()
            ->where('file_path', 'like', 'file://%')
            ->orderBy('id')
            ->chunkById(200, function (Collection $findings) use (&$repaired, &$merged, &$skipped, $dryRun): void {
                foreach ($findings as $finding) {
                    /** @var LocalFinding $finding */
                    $outcome = $this->repairOne($finding, $dryRun);

                    match ($outcome) {
                        'repaired' => $repaired++,
                        'merged' => $merged++,
                        'skipped' => $skipped[] = ['id' => $finding->id, 'file_path' => $finding->file_path],
                    };
                }
            });

        return ['repaired' => $repaired, 'merged' => $merged, 'skipped' => $skipped];
    }

    /**
     * @return 'repaired'|'merged'|'skipped'
     */
    private function repairOne(LocalFinding $finding, bool $dryRun): string
    {
        $root = $this->deriveRoot($finding->file_path);

        if ($root === null) {
            return 'skipped';
        }

        $relative = $this->normalizer->normalize($finding->file_path, $root);
        $newHash = LocalFinding::computeDedupHash((string) $finding->rule_id, $relative, $finding->start_line);

        $twin = LocalFinding::query()
            ->where('owner_type', $finding->owner_type)
            ->where('owner_id', $finding->owner_id)
            ->where('kind', $finding->kind)
            ->where('dedup_hash', $newHash)
            ->where('id', '!=', $finding->id)
            ->first();

        if ($dryRun) {
            return $twin === null ? 'repaired' : 'merged';
        }

        DB::transaction(function () use ($finding, $relative, $newHash, $twin): void {
            if ($twin === null) {
                $this->repairInPlace($finding, $relative, $newHash);
            } else {
                $this->mergeIntoTwin($finding, $twin);
            }
        });

        return $twin === null ? 'repaired' : 'merged';
    }

    private function repairInPlace(LocalFinding $finding, string $relativePath, string $newHash): void
    {
        $oldPath = $finding->file_path;

        $finding->forceFill([
            'file_path' => $relativePath,
            'dedup_hash' => $newHash,
        ])->save();

        $this->recorder->recordLocalFindingFilePathRepaired(LocalFinding::class, (string) $finding->id, [
            'old_file_path' => $oldPath,
            'new_file_path' => $relativePath,
        ]);
    }

    private function mergeIntoTwin(LocalFinding $stale, LocalFinding $twin): void
    {
        $movedComments = LocalFindingComment::query()->where('local_finding_id', $stale->id)->update(['local_finding_id' => $twin->id]);
        $movedWorkItemLinks = LocalFindingWorkItemLink::query()->where('local_finding_id', $stale->id)->update(['local_finding_id' => $twin->id]);

        $twinUpdates = [
            'first_seen_at' => $this->earliest(
                $stale->first_seen_at === null ? null : Carbon::parse((string) $stale->first_seen_at),
                $twin->first_seen_at === null ? null : Carbon::parse((string) $twin->first_seen_at),
            ),
        ];

        if ($stale->correlated_security_event_id !== null && $twin->correlated_security_event_id === null) {
            $twinUpdates['correlated_security_event_id'] = $stale->correlated_security_event_id;
            $twinUpdates['correlation_method'] = $stale->correlation_method;
        }

        $twin->forceFill($twinUpdates)->save();

        $staleId = $stale->id;
        $stale->delete();

        $this->recorder->recordLocalFindingMerged(LocalFinding::class, (string) $twin->id, [
            'merged_from_id' => $staleId,
            'moved_comments' => $movedComments,
            'moved_work_item_links' => $movedWorkItemLinks,
        ]);
    }

    private function earliest(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a->lt($b) ? $a : $b;
    }

    /**
     * Derives the absolute clone/scratch directory a stored `file_path` was rooted
     * at, from the path shape alone — no regex, just prefix/segment checks. Returns
     * null (the row is skipped) when the shape doesn't match either known collector.
     */
    private function deriveRoot(string $filePath): ?string
    {
        $path = $this->normalizer->normalize($filePath, null);

        $workspace = rtrim((string) config('static_analysis_collection.workspace_path'), '/');

        if ($workspace !== '' && str_starts_with($path, $workspace . '/')) {
            $remainder = substr($path, strlen($workspace) + 1);
            $segments = explode('/', $remainder);

            if (($segments[1] ?? null) === 'work') {
                return $workspace . '/' . $segments[0] . '/work';
            }
        }

        if (str_starts_with($path, '/tmp/')) {
            $afterTmp = substr($path, strlen('/tmp/'));
            $firstSegment = explode('/', $afterTmp)[0];

            if (str_starts_with($firstSegment, 'tmp.')) {
                return '/tmp/' . $firstSegment;
            }
        }

        return null;
    }
}
