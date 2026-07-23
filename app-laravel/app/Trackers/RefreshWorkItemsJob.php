<?php

namespace App\Trackers;

use App\Models\WorkItemLink;
use App\Sync\SystemIntegrationRuntime;
use App\Trackers\Contracts\Tracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use RuntimeException;

final class RefreshWorkItemsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 600;

    public function __construct(public readonly ?string $trackerId = null) {}

    public function uniqueId(): string
    {
        return 'refresh-work-items:' . ($this->trackerId ?? 'all');
    }

    public function handle(
        SystemIntegrationRuntime $runtime,
        WorkItemRefreshService $service,
    ): void {
        $trackerIds = $this->trackerId !== null
            ? collect([$this->trackerId])
            : WorkItemLink::query()
                ->distinct()
                ->orderBy('tracker_id')
                ->pluck('tracker_id');

        foreach ($trackerIds as $trackerId) {
            $trackerId = (string) $trackerId;

            if ($trackerId === '') {
                continue;
            }

            $tracker = $runtime->tracker($trackerId);

            if ($tracker === null) {
                if ($this->trackerId === $trackerId) {
                    throw new RuntimeException("Tracker {$trackerId} is not registered.");
                }

                continue;
            }

            $runtime->runTracker($trackerId, fn (Tracker $tracker) => $service->refreshTracker($trackerId, $tracker));
        }
    }
}
