<?php

namespace App\Assets;

use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Sources\AzDo\AzDoNormalizer;

/**
 * Read-only counterpart to AttachmentTargetResolver: looks up the
 * SoftwareSystem/SecurityContainer ids for an AzDO project/repository GUID
 * pair without ever creating a row.
 *
 * Used by error-log writers to record which system/container a failure
 * concerns. Unlike AttachmentTargetResolver (which upserts on the same
 * natural keys), this must never fabricate an inventory row just because a
 * repository-collection/static-analysis job failed before ever resolving or
 * creating one (e.g. a clone failure) — a failure log entry with no
 * resolvable owner simply carries no owner FK.
 */
final class AzDoOwnerLookup
{
    /** @return array{software_system_id: int|null, security_container_id: int|null} */
    public function forAzDoRepository(?string $projectId, ?string $repositoryId): array
    {
        if ($projectId === null || $projectId === '') {
            return ['software_system_id' => null, 'security_container_id' => null];
        }

        $systemId = SoftwareSystem::query()
            ->where('source_id', AzDoNormalizer::SOURCE_ID)
            ->where('source_system_id', $projectId)
            ->value('id');
        $systemId = is_numeric($systemId) ? (int) $systemId : null;

        if ($systemId === null || $repositoryId === null || $repositoryId === '') {
            return ['software_system_id' => $systemId, 'security_container_id' => null];
        }

        // Keyed on the system the source reports; report the container's current link, which
        // differs when an operator re-linked it.
        $container = SecurityContainer::query()
            ->where('source_software_system_id', $systemId)
            ->where('source_container_id', $repositoryId)
            ->first(['id', 'software_system_id']);

        return [
            'software_system_id' => $container === null ? $systemId : (int) $container->software_system_id,
            'security_container_id' => $container === null ? null : (int) $container->id,
        ];
    }
}
