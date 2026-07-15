<?php

namespace App\Integrations;

use App\Credentials\Vault;
use App\SourceControl\Contracts\SourceControlProvider;
use App\SourceControl\Registry as SourceControlRegistry;
use App\Sources\Contracts\Source;
use App\Sources\Registry as SourceRegistry;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Registry as TrackerRegistry;

final class SystemIntegrationRuntime
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly TrackerRegistry $trackers,
        private readonly SourceControlRegistry $sourceControls,
        private readonly Vault $vault,
    ) {}

    public function source(string $sourceId): ?Source
    {
        return $this->sources->find($sourceId);
    }

    public function sourceControl(string $id): ?SourceControlProvider
    {
        return $this->sourceControls->find($id);
    }

    public function tracker(string $trackerId): ?Tracker
    {
        return $this->trackers->find($trackerId);
    }

    /**
     * @template TReturn
     *
     * @param  callable(Source): TReturn  $callback
     * @return TReturn
     */
    public function runSource(string $sourceId, callable $callback): mixed
    {
        $source = $this->source($sourceId) ?? throw new \RuntimeException("Source {$sourceId} is not registered.");

        return $this->vault->runAsOwner(null, fn (): mixed => $callback($source), true);
    }

    /**
     * @template TReturn
     *
     * @param  callable(Tracker): TReturn  $callback
     * @return TReturn
     */
    public function runTracker(string $trackerId, callable $callback): mixed
    {
        $tracker = $this->tracker($trackerId) ?? throw new \RuntimeException("Tracker {$trackerId} is not registered.");

        return $this->vault->runAsOwner(null, fn (): mixed => $callback($tracker), true);
    }

    /**
     * @template TReturn
     *
     * @param  callable(SourceControlProvider): TReturn  $callback
     * @return TReturn
     */
    public function runSourceControl(string $id, callable $callback): mixed
    {
        $provider = $this->sourceControl($id) ?? throw new \RuntimeException("Source Control provider {$id} is not registered.");

        return $this->vault->runAsOwner(null, fn (): mixed => $callback($provider), true);
    }
}
