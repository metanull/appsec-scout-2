<?php

namespace App\Trackers;

use App\Integrations\IntegrationSettingsRepository;
use App\Models\WorkItemLink;
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
        Registry $registry,
        WorkItemRefreshService $service,
        ?IntegrationSettingsRepository $settings = null,
    ): void {
        $settings ??= app(IntegrationSettingsRepository::class);

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

            $tracker = $registry->find($trackerId);

            if ($tracker === null) {
                if ($this->trackerId === $trackerId) {
                    $settings->markSyncResult('tracker', $trackerId, false, 'Tracker is not registered.');

                    throw new RuntimeException("Tracker {$trackerId} is not registered.");
                }

                continue;
            }

            try {
                $service->refreshTracker($trackerId, $tracker);
                $settings->markSyncResult('tracker', $trackerId, true);
            } catch (\Throwable $exception) {
                $settings->markSyncResult('tracker', $trackerId, false, $exception->getMessage());

                throw $exception;
            }
        }
    }
}
