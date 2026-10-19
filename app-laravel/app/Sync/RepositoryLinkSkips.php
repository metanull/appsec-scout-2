<?php

namespace App\Sync;

use App\Assets\RepositoryMappingOutcome;
use App\Models\ErrorLog;

/**
 * Makes repository mappings skipped for a missing AzDO Repos organization visible on a sync run:
 * a `repository_links_skipped` count in the run's counts, and one ErrorLog warning per run (on
 * the first skip) rather than one per container. The ingestion itself carries on, since AzDO as
 * a Source does not require AzDO Repos as a Source Control provider.
 */
final class RepositoryLinkSkips
{
    public const string COUNT_KEY = 'repository_links_skipped';

    public const string MESSAGE = 'Repository mappings were not created because the Azure DevOps Repos organization '
        . '(azdo-repos.organization) is not configured. Add it under System Credentials so AzDO repositories '
        . 'without their own code identity get code links.';

    /**
     * @param  int  $skippedSoFar  this run's skip count before this container
     * @return int this run's skip count including this container
     */
    public static function record(RepositoryMappingOutcome $outcome, int $skippedSoFar, string $sourceId): int
    {
        if ($outcome !== RepositoryMappingOutcome::OrganizationNotConfigured) {
            return $skippedSoFar;
        }

        if ($skippedSoFar === 0) {
            ErrorLog::query()->create([
                'level' => 'warning',
                'channel' => 'sync',
                'message' => self::MESSAGE,
                'context_json' => [
                    'source_id' => $sourceId,
                    'path' => 'repository-linking',
                ],
                'trace' => null,
                'occurred_at' => now(),
            ]);
        }

        return $skippedSoFar + 1;
    }
}
