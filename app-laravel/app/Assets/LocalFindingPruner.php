<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\LocalFindingComment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Keeps the local findings table to what triage needs, without ever forgetting a decision.
 *
 * - Compiler diagnostics stored before the import skipped them (CompilerDiagnostics) are
 *   deleted: no scan reports them any more, so they cannot come back. One linked to a work
 *   item is kept.
 * - A closed (Dismissed or Resolved) finding no scan has reported for the given number of days
 *   is compacted rather than deleted: its scanner metadata and description are dropped, and so
 *   are its comments except the latest, which records why it is closed. The row keeps its
 *   identity (owner, kind, dedup_hash) and status, so a later scan that reports it again
 *   matches it and leaves the operator's decision in place instead of opening a new finding.
 *   That scan also stores its metadata and description again.
 */
final class LocalFindingPruner
{
    private const CLOSED_STATES = [EventState::Dismissed, EventState::Resolved];

    private const CHUNK = 1000;

    public function __construct(private readonly Recorder $recorder) {}

    /**
     * @return array{compiler_diagnostics_deleted: int, findings_compacted: int, comments_deleted: int}
     */
    public function prune(int $compactAfterDays, bool $dryRun): array
    {
        $result = [
            'compiler_diagnostics_deleted' => $this->deleteCompilerDiagnostics($dryRun),
            ...$this->compactClosed(now()->subDays($compactAfterDays), $dryRun),
        ];

        if (! $dryRun && array_sum($result) > 0) {
            $this->recorder->recordAdminAction('local_findings_pruned', [...$result, 'compact_after_days' => $compactAfterDays]);
        }

        return $result;
    }

    private function deleteCompilerDiagnostics(bool $dryRun): int
    {
        $deleted = 0;

        LocalFinding::query()
            ->where('report_kind', AttachmentIngestionService::KIND_CODE_QUALITY_DOTNET)
            ->where('rule_id', 'like', 'CS%')
            ->whereDoesntHave('workItemLinks')
            ->select(['id', 'report_kind', 'rule_id'])
            ->chunkById(self::CHUNK, function (Collection $findings) use ($dryRun, &$deleted): void {
                $ids = $findings
                    ->filter(fn (LocalFinding $finding): bool => CompilerDiagnostics::isCompilerDiagnostic((string) $finding->report_kind, (string) $finding->rule_id))
                    ->modelKeys();

                if (! $dryRun && $ids !== []) {
                    // Their comments and work item links go with them (cascadeOnDelete).
                    LocalFinding::query()->whereKey($ids)->delete();
                }

                $deleted += count($ids);
            });

        return $deleted;
    }

    /**
     * @return array{findings_compacted: int, comments_deleted: int}
     */
    private function compactClosed(CarbonInterface $notSeenSince, bool $dryRun): array
    {
        $result = ['findings_compacted' => 0, 'comments_deleted' => 0];

        LocalFinding::query()
            ->whereIn('status', array_map(fn (EventState $state): string => $state->value, self::CLOSED_STATES))
            ->where('last_seen_at', '<', $notSeenSince)
            ->where(fn (Builder $query) => $query->whereNotNull('metadata')->orWhereNotNull('description'))
            ->select('id')
            ->chunkById(self::CHUNK, function (Collection $findings) use ($dryRun, &$result): void {
                $ids = $findings->modelKeys();
                $comments = LocalFindingComment::query()->whereIn('local_finding_id', $ids);
                $latestCommentIds = (clone $comments)->toBase()->groupBy('local_finding_id')->selectRaw('max(id) as latest_id')->pluck('latest_id')->all();

                $result['findings_compacted'] += count($ids);
                $result['comments_deleted'] += (clone $comments)->count() - count($latestCommentIds);

                if ($dryRun) {
                    return;
                }

                $comments->whereNotIn('id', $latestCommentIds)->delete();
                // toBase(): compacting is not a change to the finding, so updated_at stays the
                // time of its last real change.
                LocalFinding::query()->whereKey($ids)->toBase()->update(['metadata' => null, 'description' => null]);
            });

        return $result;
    }
}
