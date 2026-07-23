<?php

namespace App\Trackers;

use App\Trackers\Contracts\Tracker;
use Illuminate\Contracts\Container\Container;

final class Registry
{
    /** @var list<Tracker>|null */
    private ?array $resolvedAll = null;

    public function __construct(
        private readonly Container $container,
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
