<?php

declare(strict_types=1);

namespace App\Assets;

use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use Illuminate\Support\Collection;

/**
 * Resolves every SecurityContainer descending from a SoftwareSystem or
 * SoftwareAsset, shared by the SBOM and findings zip builders since both
 * need to walk the same System -> Container / Asset -> System -> Container
 * hierarchy.
 */
final class ContainerHierarchyResolver
{
    /** @return Collection<int, SecurityContainer> */
    public function containersFor(SoftwareSystem|SoftwareAsset $owner): Collection
    {
        if ($owner instanceof SoftwareSystem) {
            return $owner->containers;
        }

        return SecurityContainer::query()
            ->whereIn('software_system_id', $owner->softwareSystems()->pluck('id'))
            ->get();
    }
}
