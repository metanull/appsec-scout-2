<?php

namespace App\Sources;

use App\Sources\Contracts\Source;
use Illuminate\Contracts\Container\Container;

final class Registry
{
    /** @var list<Source>|null */
    private ?array $resolved = null;

    public function __construct(private readonly Container $container) {}

    /**
     * Return all enabled source implementations.
     *
     * A source is considered enabled when `integration_settings.<id>.enabled`
     * is truthy in the application configuration.
     *
     * @return list<Source>
     */
    public function enabled(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $this->resolved = [];

        foreach ($this->container->tagged('appsec-scout.source') as $source) {
            /** @var Source $source */
            if ((bool) config("integration_settings.{$source->id()}.enabled", false)) {
                $this->resolved[] = $source;
            }
        }

        return $this->resolved;
    }

    public function find(string $id): ?Source
    {
        foreach ($this->enabled() as $source) {
            if ($source->id() === $id) {
                return $source;
            }
        }

        return null;
    }
}
