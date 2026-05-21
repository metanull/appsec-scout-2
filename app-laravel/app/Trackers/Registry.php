<?php

namespace App\Trackers;

use App\Trackers\Contracts\Tracker;
use Illuminate\Contracts\Container\Container;

final class Registry
{
    /** @var list<Tracker>|null */
    private ?array $resolved = null;

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<Tracker>
     */
    public function enabled(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $this->resolved = [];

        foreach ($this->container->tagged('appsec-scout.tracker') as $tracker) {
            /** @var Tracker $tracker */
            if ((bool) config("integration_settings.{$tracker->id()}.enabled", false)) {
                $this->resolved[] = $tracker;
            }
        }

        return $this->resolved;
    }

    public function find(string $id): ?Tracker
    {
        foreach ($this->enabled() as $tracker) {
            if ($tracker->id() === $id) {
                return $tracker;
            }
        }

        return null;
    }
}
