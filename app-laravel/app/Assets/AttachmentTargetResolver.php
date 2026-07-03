<?php

namespace App\Assets;

use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use InvalidArgumentException;

/**
 * Resolves the SoftwareSystem/SecurityContainer row that an out-of-band file
 * import (SBOM, dependency report, HTTP headers, pipeline run, ...) should
 * attach to, using the same natural keys FetchSourceJob upserts on
 * (source_id + source_system_id, software_system_id + source_container_id)
 * so the row converges with whatever a live source sync creates or already created.
 */
final class AttachmentTargetResolver
{
    public function resolveSystem(string $sourceId, string $sourceSystemId, ?string $systemName): SoftwareSystem
    {
        $system = SoftwareSystem::query()->firstOrNew([
            'source_id' => $sourceId,
            'source_system_id' => $sourceSystemId,
        ]);

        if (! $system->exists) {
            $name = trim((string) $systemName);

            if ($name === '') {
                throw new InvalidArgumentException(
                    "No software system found for source '{$sourceId}' / '{$sourceSystemId}'. Provide --system-name to create it.",
                );
            }

            $system->fill([
                'source_id' => $sourceId,
                'source_system_id' => $sourceSystemId,
                'name' => $name,
                'first_seen_at' => now(),
            ]);
        }

        $system->last_seen_at = now();
        $system->save();

        return $system;
    }

    public function resolveContainer(
        SoftwareSystem $system,
        string $sourceContainerId,
        ?string $containerName,
        ?string $kind,
    ): SecurityContainer {
        $container = SecurityContainer::query()->firstOrNew([
            'software_system_id' => $system->id,
            'source_container_id' => $sourceContainerId,
        ]);

        if (! $container->exists) {
            $name = trim((string) $containerName);

            if ($name === '') {
                throw new InvalidArgumentException(
                    "No security container found for '{$sourceContainerId}' under software system #{$system->id}. Provide --container-name to create it.",
                );
            }

            $container->fill([
                'software_system_id' => $system->id,
                'source_container_id' => $sourceContainerId,
                'name' => $name,
                'kind' => $kind,
                'first_seen_at' => now(),
            ]);
        }

        $container->last_seen_at = now();
        $container->save();

        return $container;
    }
}
