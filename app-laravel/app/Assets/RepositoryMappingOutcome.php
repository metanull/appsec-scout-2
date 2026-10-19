<?php

namespace App\Assets;

/**
 * What AzDoProjectLinker::ensureRepositoryMapping() did for one container, so a sync run can
 * surface the one outcome that needs operator attention instead of skipping it silently.
 */
enum RepositoryMappingOutcome
{
    /** The container was handled: mapped now, already mapped, or needs no mapping. */
    case Handled;

    /** No mapping was created because the AzDO Repos organization is not configured. */
    case OrganizationNotConfigured;
}
