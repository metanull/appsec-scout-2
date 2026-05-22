<?php

namespace App\Sources;

use App\Integrations\IntegrationSettingsRepository;
use App\Sources\Contracts\Source;
use Illuminate\Contracts\Container\Container;

final class Registry
{
    /** @var list<Source>|null */
    private ?array $resolvedAll = null;

    /** @var list<Source>|null */
    private ?array $resolvedEnabled = null;

    public function __construct(
        private readonly Container $container,
        private readonly IntegrationSettingsRepository $settings,
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

    /**
     * @return list<Source>
     */
    public function enabled(): array
    {
        if ($this->resolvedEnabled !== null) {
            return $this->resolvedEnabled;
        }

        $this->resolvedEnabled = array_values(array_filter(
            $this->all(),
            fn (Source $source): bool => $this->settings->isEnabled('source', $source->id()),
        ));

        return $this->resolvedEnabled;
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
