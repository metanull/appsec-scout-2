<?php

namespace App\Assets;

use App\Assets\Parsers\SarifArtifactUriNormalizer;
use App\Audit\Recorder;
use App\Database\TextSearch;
use App\Models\LocalFinding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair for `local_findings` rows whose `file_path` is not the real
 * repository-relative path `App\Assets\Parsers\SarifArtifactUriNormalizer` now
 * stores: an absolute container path (`file:///workspace-scratch/<uuid>/work/...`
 * from the in-app collector, `file:///tmp/tmp.XXXXXXXXXX/...` from the shell
 * collector), or a still percent-encoded relative uri (`Class%20Libraries/x.cs`)
 * stored before relative uris were decoded. Rewrites `file_path`/`dedup_hash` in place,
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
        private readonly LocalFindingMerger $merger,
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
            ->where(fn (Builder $query): Builder => $query
                ->where('file_path', 'like', 'file://%')
                ->orWhere(fn (Builder $encoded): Builder => TextSearch::apply($encoded, ['file_path'], '%')))
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
        $relative = $this->relativePath($finding->file_path);

        if ($relative === null) {
            return 'skipped';
        }

        $metadata = $finding->getAttribute('metadata');
        $newHash = LocalFinding::identityHash(
            (string) $finding->kind,
            (string) $finding->rule_id,
            $relative,
            $finding->start_line,
            $finding->package_name,
            $finding->package_version,
            LocalFinding::sarifFingerprint(is_array($metadata) ? ($metadata['result'] ?? null) : null),
        );

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

    /**
     * The repository-relative path for a stored `file_path`, or null when it cannot
     * be repaired: an absolute path of no known collector shape, or an encoded-looking
     * path that decodes to itself.
     */
    private function relativePath(string $filePath): ?string
    {
        if (! str_starts_with($filePath, 'file://')) {
            $decoded = $this->normalizer->normalize($filePath, null);

            return $decoded !== $filePath ? $decoded : null;
        }

        $root = $this->deriveRoot($filePath);

        return $root !== null ? $this->normalizer->normalize($filePath, $root) : null;
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
        $this->merger->merge($stale, $twin);
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
