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
 *
 * The optional url/description/metadata arguments enrich a row only when this
 * import is the one that creates it, so an "ops-first" row (imported before any
 * live source sync has ever seen the project/repo) is born with the same
 * description, web links and SourceContextFacts a sync would have written,
 * instead of a bare name-only stub. They are never applied to a row that
 * already exists: a live source sync remains the authoritative writer, so its
 * values are preserved and never overwritten by a later import.
 */
final class AttachmentTargetResolver
{
    /**
     * @param  array<string, mixed>  $metadata  enrichment facts, applied on create only
     */
    public function resolveSystem(
        string $sourceId,
        string $sourceSystemId,
        ?string $systemName,
        ?string $url = null,
        ?string $description = null,
        array $metadata = [],
    ): SoftwareSystem {
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
                'description' => $description,
                'url' => $url,
                'metadata' => $metadata === [] ? null : $metadata,
                'first_seen_at' => now(),
            ]);
        }

        $system->last_seen_at = now();
        $system->save();

        return $system;
    }

    /**
     * @param  array<string, mixed>  $metadata  enrichment facts, applied on create only
     */
    public function resolveContainer(
        SoftwareSystem $system,
        string $sourceContainerId,
        ?string $containerName,
        ?string $kind,
        ?string $url = null,
        array $metadata = [],
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
                'url' => $url,
                'metadata' => $metadata === [] ? null : $metadata,
                'first_seen_at' => now(),
            ]);
        }

        $container->last_seen_at = now();
        $container->save();

        return $container;
    }
}
