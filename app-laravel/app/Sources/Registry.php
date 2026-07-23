<?php

namespace App\Sources;

use App\Sources\Contracts\Source;
use Illuminate\Contracts\Container\Container;

final class Registry
{
    /** @var list<Source>|null */
    private ?array $resolvedAll = null;

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @return list<Source>
     */
    public function all(): array
    {
        if ($this->resolvedAll !== null) {
            return $this->resolvedAll;
        }

        $this->resolvedAll = [];

        foreach ($this->container->tagged('appsec-scout.source') as $source) {
            /** @var Source $source */
            $this->resolvedAll[] = $source;
        }

        return $this->resolvedAll;
    }

    public function find(string $id): ?Source
    {
        foreach ($this->all() as $source) {
            if ($source->id() === $id) {
                return $source;
            }
        }

        return null;
    }
}
