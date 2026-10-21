<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Models\LocalFinding;
use App\Models\LocalFindingComment;
use App\Models\LocalFindingWorkItemLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Folds a duplicate LocalFinding row into the row that represents the same finding, so the
 * operator keeps one row with all its history: the duplicate's comments and work-item links
 * move over (a link the survivor already has is dropped), the survivor takes the duplicate's
 * correlation and severity override when it has none, and its first/last seen dates span both.
 * The survivor's own status is kept: choosing which row survives is the caller's decision.
 */
final class LocalFindingMerger
{
    public function __construct(private readonly Recorder $recorder) {}

    public function merge(LocalFinding $duplicate, LocalFinding $survivor): void
    {
        DB::transaction(function () use ($duplicate, $survivor): void {
            $movedComments = LocalFindingComment::query()
                ->where('local_finding_id', $duplicate->id)
                ->update(['local_finding_id' => $survivor->id]);

            $movedWorkItemLinks = $this->moveWorkItemLinks($duplicate, $survivor);

            $updates = [
                'first_seen_at' => $this->pick($duplicate->first_seen_at, $survivor->first_seen_at, earliest: true),
                'last_seen_at' => $this->pick($duplicate->last_seen_at, $survivor->last_seen_at, earliest: false),
            ];

            if ($survivor->correlated_security_event_id === null && $duplicate->correlated_security_event_id !== null) {
                $updates['correlated_security_event_id'] = $duplicate->correlated_security_event_id;
                $updates['correlation_method'] = $duplicate->correlation_method;
            }

            if ($survivor->getAttribute('overridden_severity') === null && $duplicate->getAttribute('overridden_severity') !== null) {
                $updates['overridden_severity'] = $duplicate->getAttribute('overridden_severity');
            }

            $duplicateId = $duplicate->id;
            $duplicate->delete();
            $survivor->forceFill($updates)->save();

            $this->recorder->recordLocalFindingMerged(LocalFinding::class, (string) $survivor->id, [
                'merged_from_id' => $duplicateId,
                'moved_comments' => $movedComments,
                'moved_work_item_links' => $movedWorkItemLinks,
            ]);
        });
    }

    private function moveWorkItemLinks(LocalFinding $duplicate, LocalFinding $survivor): int
    {
        $moved = 0;

        foreach (LocalFindingWorkItemLink::query()->where('local_finding_id', $duplicate->id)->get() as $link) {
            $alreadyLinked = LocalFindingWorkItemLink::query()
                ->where('local_finding_id', $survivor->id)
                ->where('tracker_id', $link->tracker_id)
                ->where('work_item_id', $link->work_item_id)
                ->exists();

            if ($alreadyLinked) {
                $link->delete();

                continue;
            }

            $link->forceFill(['local_finding_id' => $survivor->id])->save();
            $moved++;
        }

        return $moved;
    }

    private function pick(mixed $a, mixed $b, bool $earliest): ?Carbon
    {
        $a = $a === null ? null : Carbon::parse((string) $a);
        $b = $b === null ? null : Carbon::parse((string) $b);

        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $earliest === $a->lt($b) ? $a : $b;
    }
}
