<?php

namespace App\Sync;

use App\Credentials\CredentialField;
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

    /**
     * Whether every required credential field for an integration has a non-empty
     * system credential configured. Used to scope system-credentialed sweeps
     * (full inventory sync, reconciliation project discovery) to the integrations
     * an operator has actually set up, instead of hard-failing on a registered but
     * unconfigured Source/Tracker/Source Control provider.
     *
     * @param  iterable<CredentialField>  $credentialFields
     */
    public function hasRequiredSystemCredentials(iterable $credentialFields): bool
    {
        return $this->vault->runAsOwner(null, function () use ($credentialFields): bool {
            foreach ($credentialFields as $field) {
                if (! $field->required) {
                    continue;
                }

                $value = $this->vault->get($field->key, null);

                if (! is_string($value) || trim($value) === '') {
                    return false;
                }
            }

            return true;
        });
    }

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

        return $this->vault->runAsOwner(null, fn (): mixed => $callback($source));
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

        return $this->vault->runAsOwner(null, fn (): mixed => $callback($tracker));
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

        return $this->vault->runAsOwner(null, fn (): mixed => $callback($provider));
    }
}
