<?php

namespace App\Trackers;

use App\Audit\Recorder;
use App\Models\SecurityEvent;
use App\Models\WorkItemLink;
use App\Trackers\Contracts\RateLimitedTracker;
use App\Trackers\Contracts\Tracker;
use Illuminate\Database\DatabaseManager;

final class WorkItemRefreshService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Recorder $recorder,
    ) {}

    public function refreshTracker(string $trackerId, Tracker $tracker): void
    {
        $workItemIds = WorkItemLink::query()
            ->where('tracker_id', $trackerId)
            ->distinct()
            ->orderBy('work_item_id')
            ->pluck('work_item_id');

        foreach ($workItemIds as $workItemId) {
            if (! is_string($workItemId) || $workItemId === '') {
                continue;
            }

            $workItem = $tracker->getWorkItem($workItemId);

            if ($workItem === null) {
                continue;
            }

            $this->db->transaction(function () use ($trackerId, $workItemId, $workItem): void {
                $links = WorkItemLink::query()
                    ->where('tracker_id', $trackerId)
                    ->where('work_item_id', $workItemId)
                    ->get();

                foreach ($links as $link) {
                    $previousState = $link->work_item_state;

                    $link->forceFill([
                        'work_item_title' => $workItem->title,
                        'work_item_state' => $workItem->state,
                        'work_item_url' => $workItem->url,
                        'synced_at' => now(),
                    ])->save();

                    if ($previousState !== $workItem->state) {
                        $this->recorder->recordTrackerStateChanged(SecurityEvent::class, (string) $link->event_id, [
                            'tracker_id' => $trackerId,
                            'work_item_id' => $workItemId,
                            'from' => $previousState,
                            'to' => $workItem->state,
                        ]);
                    }
                }
            });

            $delay = $this->rateLimitDelay($tracker);

            if ($delay > 0) {
                sleep($delay);
            }
        }
    }

    private function rateLimitDelay(Tracker $tracker): int
    {
        if (! $tracker instanceof RateLimitedTracker) {
            return 0;
        }

        return max(0, $tracker->rateLimitDelay());
    }
}
