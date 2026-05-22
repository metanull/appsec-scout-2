<?php

namespace App\Integrations;

use App\Sources\Registry as SourceRegistry;
use App\Sync\FetchSourceJob;
use App\Trackers\RefreshWorkItemsJob;
use App\Trackers\Registry as TrackerRegistry;

final class DispatchDueIntegrations
{
    public function __construct(
        private readonly IntegrationSettingsRepository $settings,
        private readonly SourceRegistry $sources,
        private readonly TrackerRegistry $trackers,
    ) {}

    public function dispatchDue(): int
    {
        $count = 0;

        foreach ($this->sources->all() as $source) {
            if (! $this->settings->isDue('source', $source->id())) {
                continue;
            }

            FetchSourceJob::dispatch($source->id());
            $count++;
        }

        foreach ($this->trackers->all() as $tracker) {
            if (! $this->settings->isDue('tracker', $tracker->id())) {
                continue;
            }

            RefreshWorkItemsJob::dispatch($tracker->id());
            $count++;
        }

        return $count;
    }
}
