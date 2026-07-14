<?php

namespace App\SourceControl;

use App\Integrations\IntegrationSettingsRepository;
use App\SourceControl\Contracts\SourceControlProvider;
use Illuminate\Contracts\Container\Container;

final class Registry
{
    /** @var list<SourceControlProvider>|null */
    private ?array $resolvedAll = null;

    /** @var list<SourceControlProvider>|null */
    private ?array $resolvedEnabled = null;

    public function __construct(
        private readonly Container $container,
        private readonly IntegrationSettingsRepository $settings,
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

    /**
     * @return list<SourceControlProvider>
     */
    public function enabled(): array
    {
        if ($this->resolvedEnabled !== null) {
            return $this->resolvedEnabled;
        }

        $this->resolvedEnabled = array_values(array_filter(
            $this->all(),
            fn (SourceControlProvider $provider): bool => $this->settings->isEnabled('source_control', $provider->id()),
        ));

        return $this->resolvedEnabled;
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
