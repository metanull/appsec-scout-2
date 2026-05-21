<?php

namespace App\Trackers;

use App\Models\WorkItemLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class RefreshWorkItemsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(Registry $registry, WorkItemRefreshService $service): void
    {
        $trackerIds = WorkItemLink::query()
            ->distinct()
            ->orderBy('tracker_id')
            ->pluck('tracker_id');

        foreach ($trackerIds as $trackerId) {
            if (! is_string($trackerId) || $trackerId === '') {
                continue;
            }

            $tracker = $registry->find($trackerId);

            if ($tracker === null) {
                continue;
            }

            $service->refreshTracker($trackerId, $tracker);
        }
    }
}
