<?php

namespace App\Mcp\Support;

use App\Assets\ContainerLinkChange;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;

/** Shapes the dry-run and applied output of the container link tools, and the manual-link keys of the inventory tools. */
final class ContainerLinkResult
{
    /** @return array<string, mixed> */
    public static function preview(SecurityContainer $container, SoftwareSystem $target, string $note): array
    {
        return [
            'dry_run' => true,
            'note' => $note,
            'container_id' => $container->id,
            'previous_system_id' => $container->software_system_id,
            'system_id' => $target->id,
            'manual_link' => $target->id !== $container->source_software_system_id,
            'would_move' => [
                'events' => SecurityEvent::query()->where('container_id', $container->id)->count(),
                'local_findings' => LocalFinding::query()->whereMorphedTo('owner', $container)->count(),
                'software_components' => SoftwareComponent::query()->whereMorphedTo('owner', $container)->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function applied(ContainerLinkChange $change): array
    {
        return [
            'dry_run' => false,
            'container_id' => $change->container->id,
            'previous_system_id' => $change->previousSystemId,
            'system_id' => $change->container->software_system_id,
            'manual_link' => $change->container->isSystemOverridden(),
            'moved' => [
                'events' => $change->events,
                'local_findings' => $change->localFindings,
                'software_components' => $change->softwareComponents,
            ],
        ];
    }

    /** @return array{manual_link: bool, source_system: array{id: int, name: string}|null} */
    public static function linkKeys(SecurityContainer $container): array
    {
        $source = $container->isSystemOverridden() ? $container->sourceSoftwareSystem : null;

        return [
            'manual_link' => $container->isSystemOverridden(),
            'source_system' => $source === null ? null : ['id' => $source->id, 'name' => $source->name],
        ];
    }
}
