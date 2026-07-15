<?php

namespace App\SourceControl\Contracts;

use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\SystemDto;

/**
 * Optional mixin for a SourceControlProvider that can enumerate the Projects/Repositories its
 * credential can see, reusing the same SystemDto/ContainerDto shapes App\Sources\Contracts\Source
 * already returns from fetchSystems()/fetchContainers() — so both feed the identical
 * App\Sync\SystemContainerUpserter path. "Organization" is deliberately not a modeled hierarchy
 * level here: it is the scope of the provider's own credential (one credential = one
 * organization), not a new database row.
 */
interface EnumeratesInventory
{
    /** @return iterable<SystemDto> */
    public function fetchProjects(): iterable;

    /** @return iterable<ContainerDto> */
    public function fetchRepositories(SystemDto $project): iterable;
}
