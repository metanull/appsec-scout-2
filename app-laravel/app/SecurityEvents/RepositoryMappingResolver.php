<?php

namespace App\SecurityEvents;

use App\Models\RepositoryMapping;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;

final class RepositoryMappingResolver
{
    public function resolve(SecurityEvent $event): ?RepositoryMapping
    {
        $event->loadMissing([
            'container.repositoryMappings.repositoryProvider',
            'softwareSystem.repositoryMappings.repositoryProvider',
        ]);

        $container = $event->container;

        if ($container instanceof SecurityContainer) {
            $mapping = $this->firstRepositoryMapping($container->repositoryMappings);

            if ($mapping !== null) {
                return $mapping;
            }
        }

        $system = $event->softwareSystem;

        if ($system instanceof SoftwareSystem) {
            $mapping = $this->firstRepositoryMapping($system->repositoryMappings);

            if ($mapping !== null) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * @param  iterable<RepositoryMapping>  $mappings
     */
    private function firstRepositoryMapping(iterable $mappings): ?RepositoryMapping
    {
        foreach ($mappings as $mapping) {
            return $mapping;
        }

        return null;
    }
}
