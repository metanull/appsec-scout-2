<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Models\Attachment;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\LocalFindingComment;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Brings an owner's local findings of one tool in line with that tool's latest complete report:
 * a finding the report no longer contains is resolved, whatever not-yet-closed status it had,
 * and a finding the scan itself resolved that the report contains again is reopened with the
 * status it had before. Each change gets a comment and an audit entry. A status an operator set
 * (Dismissed, or a Resolved they chose) is never touched: the operator's call stands.
 *
 * Callers must only reconcile after the whole report was ingested without throwing; a partial
 * report would read as "everything else is gone".
 */
final class LocalFindingScanReconciler
{
    private const OPEN_STATES = [EventState::Open, EventState::Acknowledged, EventState::InProgress];

    public function __construct(private readonly Recorder $recorder) {}

    /** @param  list<int>  $reportedIds */
    public function reconcile(SoftwareAsset|SoftwareSystem|SecurityContainer $owner, string $kind, Attachment $report, array $reportedIds): void
    {
        $this->resolveMissing($owner, $kind, $report, $reportedIds);

        if ($reportedIds !== []) {
            $this->reopenReported($owner, $kind, $report, $reportedIds);
        }
    }

    /** @param  list<int>  $reportedIds */
    private function resolveMissing(SoftwareAsset|SoftwareSystem|SecurityContainer $owner, string $kind, Attachment $report, array $reportedIds): void
    {
        $this->scope($owner, $kind, $report)
            ->whereNotIn('id', $reportedIds)
            ->whereIn('status', array_map(fn (EventState $state): string => $state->value, self::OPEN_STATES))
            ->chunkById(500, function (Collection $findings) use ($report): void {
                $now = now();
                $body = sprintf('No longer reported by the %s scan of %s (report #%d); resolved automatically.', $report->kind, $now->toDateString(), $report->id);

                foreach ($findings->groupBy(fn (LocalFinding $finding): string => $this->statusValue($finding)) as $previous => $group) {
                    LocalFinding::query()->whereKey($group->modelKeys())->update([
                        'status' => EventState::Resolved->value,
                        'resolved_by_scan_at' => $now,
                        'status_before_scan_resolution' => $previous,
                        'updated_at' => $now,
                    ]);
                }

                $this->record($findings, $body, EventState::Resolved, $report);
            });
    }

    /** @param  list<int>  $reportedIds */
    private function reopenReported(SoftwareAsset|SoftwareSystem|SecurityContainer $owner, string $kind, Attachment $report, array $reportedIds): void
    {
        $this->scope($owner, $kind, $report)
            ->whereIn('id', $reportedIds)
            ->where('status', EventState::Resolved->value)
            ->whereNotNull('resolved_by_scan_at')
            ->chunkById(500, function (Collection $findings) use ($report): void {
                $now = now();

                foreach ($findings->groupBy(fn (LocalFinding $finding): string => $this->restoredStatus($finding)->value) as $restored => $group) {
                    LocalFinding::query()->whereKey($group->modelKeys())->update([
                        'status' => $restored,
                        'resolved_by_scan_at' => null,
                        'status_before_scan_resolution' => null,
                        'updated_at' => $now,
                    ]);

                    $body = sprintf('Reported again by the %s scan of %s (report #%d); status restored to %s.', $report->kind, $now->toDateString(), $report->id, $restored);
                    $this->record($group, $body, EventState::from($restored), $report);
                }
            });
    }

    /** @return Builder<LocalFinding> */
    private function scope(SoftwareAsset|SoftwareSystem|SecurityContainer $owner, string $kind, Attachment $report): Builder
    {
        /** @var Builder<LocalFinding> $query */
        $query = $owner->localFindings()->getQuery();

        return $query->where('kind', $kind)->where('report_kind', $report->kind);
    }

    /** @param  Collection<int, LocalFinding>  $findings */
    private function record(Collection $findings, string $body, EventState $newStatus, Attachment $report): void
    {
        $now = now();

        LocalFindingComment::query()->insert($findings->map(fn (LocalFinding $finding): array => [
            'local_finding_id' => $finding->id,
            'body' => $body,
            'author_user_id' => null,
            'created_at' => $now,
        ])->values()->all());

        foreach ($findings as $finding) {
            $this->recorder->recordStateChange(LocalFinding::class, (string) $finding->id, [
                'previous_status' => $this->statusValue($finding),
                'new_status' => $newStatus->value,
                'comment' => $body,
                'report_id' => $report->id,
            ]);
        }
    }

    private function restoredStatus(LocalFinding $finding): EventState
    {
        $previous = EventState::tryFrom((string) $finding->getAttribute('status_before_scan_resolution'));

        return $previous !== null && in_array($previous, self::OPEN_STATES, true) ? $previous : EventState::Open;
    }

    private function statusValue(LocalFinding $finding): string
    {
        $status = $finding->getAttribute('status');

        return $status instanceof EventState ? $status->value : (string) $status;
    }
}
