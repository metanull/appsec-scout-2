<?php

namespace App\SourceControl;

use App\SourceControl\Contracts\SourceControlProvider;
use Illuminate\Contracts\Container\Container;

final class Registry
{
    /** @var list<SourceControlProvider>|null */
    private ?array $resolvedAll = null;

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @return list<SourceControlProvider>
     */
    public function all(): array
    {
        if ($this->resolvedAll !== null) {
            return $this->resolvedAll;
        }

        $this->resolvedAll = [];

        foreach ($this->container->tagged('appsec-scout.source-control') as $provider) {
            /** @var SourceControlProvider $provider */
            $this->resolvedAll[] = $provider;
        }

        return $this->resolvedAll;
    }

    public function find(string $id): ?SourceControlProvider
    {
        foreach ($this->all() as $provider) {
            if ($provider->id() === $id) {
                return $provider;
            }
        }

        return null;
    }
}
