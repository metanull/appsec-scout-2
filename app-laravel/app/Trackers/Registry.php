<?php

namespace App\Trackers;

use App\Integrations\IntegrationSettingsRepository;
use App\Trackers\Contracts\Tracker;
use Illuminate\Contracts\Container\Container;

final class Registry
{
    /** @var list<Tracker>|null */
    private ?array $resolvedAll = null;

    /** @var list<Tracker>|null */
    private ?array $resolvedEnabled = null;

    public function __construct(
        private readonly Container $container,
        private readonly IntegrationSettingsRepository $settings,
    ) {}

    /**
     * @return list<Tracker>
     */
    public function all(): array
    {
        if ($this->resolvedAll !== null) {
            return $this->resolvedAll;
        }

        $this->resolvedAll = [];

        foreach ($this->container->tagged('appsec-scout.tracker') as $tracker) {
            /** @var Tracker $tracker */
            $this->resolvedAll[] = $tracker;
        }

        return $this->resolvedAll;
    }

    /**
     * @return list<Tracker>
     */
    public function enabled(): array
    {
        if ($this->resolvedEnabled !== null) {
            return $this->resolvedEnabled;
        }

        $this->resolvedEnabled = array_values(array_filter(
            $this->all(),
            fn (Tracker $tracker): bool => $this->settings->isEnabled('tracker', $tracker->id()),
        ));

        return $this->resolvedEnabled;
    }

    public function find(string $id): ?Tracker
    {
        foreach ($this->all() as $tracker) {
            if ($tracker->id() === $id) {
                return $tracker;
            }
        }

        return null;
    }
}
