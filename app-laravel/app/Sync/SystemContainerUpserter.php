<?php

namespace App\Sync;

use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\SystemDto;

/**
 * Upserts SoftwareSystem/SecurityContainer rows on the natural keys the whole
 * app relies on (source_id + source_system_id, software_system_id +
 * source_container_id) so any caller — the scheduled FetchSourceJob or an
 * on-demand inventory sync — converges on the same rows.
 */
final class SystemContainerUpserter
{
    /** @return array{system: SoftwareSystem, wasCreated: bool} */
    public function upsertSystem(string $sourceId, SystemDto $dto): array
    {
        $system = SoftwareSystem::query()->firstOrNew([
            'source_id' => $sourceId,
            'source_system_id' => $dto->sourceSystemId,
        ]);

        $wasCreated = ! $system->exists;

        $system->fill([
            'name' => $dto->name,
            'description' => $dto->description,
            'url' => $dto->url,
            'metadata' => $dto->metadata,
            'first_seen_at' => $system->first_seen_at ?? now(),
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);
        $system->save();

        return ['system' => $system, 'wasCreated' => $wasCreated];
    }

    /** @return array{container: SecurityContainer, wasCreated: bool} */
    public function upsertContainer(SoftwareSystem $system, ContainerDto $dto): array
    {
        $container = SecurityContainer::query()->firstOrNew([
            'software_system_id' => $system->id,
            'source_container_id' => $dto->sourceContainerId,
        ]);

        $wasCreated = ! $container->exists;

        $container->fill([
            'name' => $dto->name,
            'kind' => $dto->kind,
            'url' => $dto->url,
            'metadata' => $dto->metadata,
            'first_seen_at' => $container->first_seen_at ?? now(),
            'last_seen_at' => now(),
            'synced_at' => now(),
        ]);
        $container->save();

        return ['container' => $container, 'wasCreated' => $wasCreated];
    }
}
