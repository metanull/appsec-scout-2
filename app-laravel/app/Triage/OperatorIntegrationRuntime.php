<?php

namespace App\Triage;

use App\Credentials\CredentialField;
use App\Credentials\Vault;
use App\Sources\Contracts\Source;
use App\Sources\Registry as SourceRegistry;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Registry as TrackerRegistry;

final class OperatorIntegrationRuntime
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly TrackerRegistry $trackers,
        private readonly Vault $vault,
    ) {}

    /** @return list<Source> */
    public function sources(): array
    {
        return $this->sources->all();
    }

    /** @return list<Tracker> */
    public function trackers(): array
    {
        return $this->trackers->all();
    }

    public function source(string $sourceId): ?Source
    {
        return $this->sources->find($sourceId);
    }

    public function tracker(string $trackerId): ?Tracker
    {
        return $this->trackers->find($trackerId);
    }

    /** @return list<string> */
    public function missingSourceCredentialLabels(string $sourceId, int $operatorUserId): array
    {
        $source = $this->source($sourceId);

        if (! $source instanceof Source) {
            return [];
        }

        return $this->missingCredentialLabels($source->credentialFields(), $operatorUserId);
    }

    /** @return list<string> */
    public function missingTrackerCredentialLabels(string $trackerId, int $operatorUserId): array
    {
        $tracker = $this->tracker($trackerId);

        if (! $tracker instanceof Tracker) {
            return [];
        }

        return $this->missingCredentialLabels($tracker->credentialFields(), $operatorUserId);
    }

    /**
     * @template TReturn
     *
     * @param  callable(Source): TReturn  $callback
     * @return TReturn
     */
    public function runSource(string $sourceId, int $operatorUserId, callable $callback): mixed
    {
        $source = $this->source($sourceId) ?? throw new \RuntimeException("Source {$sourceId} is not registered.");

        return $this->vault->runAsOwner($operatorUserId, fn (): mixed => $callback($source));
    }

    /**
     * @template TReturn
     *
     * @param  callable(Tracker): TReturn  $callback
     * @return TReturn
     */
    public function runTracker(string $trackerId, int $operatorUserId, callable $callback): mixed
    {
        $tracker = $this->tracker($trackerId) ?? throw new \RuntimeException("Tracker {$trackerId} is not registered.");

        return $this->vault->runAsOwner($operatorUserId, fn (): mixed => $callback($tracker));
    }

    /**
     * @param  list<CredentialField>  $fields
     * @return list<string>
     */
    private function missingCredentialLabels(array $fields, int $operatorUserId): array
    {
        $missing = [];

        foreach ($fields as $field) {
            if (! $field->required) {
                continue;
            }

            $value = $this->vault->runAsOwner(
                $operatorUserId,
                fn (): ?string => $this->vault->get($field->key, null),
            );

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $field->label;
            }
        }

        return $missing;
    }
}
