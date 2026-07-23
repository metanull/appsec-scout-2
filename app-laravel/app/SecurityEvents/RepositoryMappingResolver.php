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

        return $this->resolveFromOwners($event->container, $event->softwareSystem);
    }

    /**
     * Resolve the mapping override for an arbitrary container/system pair,
     * preferring a container mapping over a system one. Shared by the
     * SecurityEvent path above and the Local Finding path, which owns a
     * container (its morph owner) and a system directly rather than through an
     * event.
     */
    public function resolveFromOwners(?SecurityContainer $container, ?SoftwareSystem $system): ?RepositoryMapping
    {
        if ($container instanceof SecurityContainer) {
            $mapping = $this->firstRepositoryMapping($container->repositoryMappings);

            if ($mapping !== null) {
                return $mapping;
            }
        }

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
