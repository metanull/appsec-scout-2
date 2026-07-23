<?php

namespace App\Sync;

use App\Assets\AzDoProjectLinker;
use App\Assets\StaleRecordSweeper;
use App\SourceControl\Contracts\EnumeratesInventory;
use App\SourceControl\Contracts\SourceControlProvider;
use App\SourceControl\Registry as SourceControlRegistry;
use App\Sources\Contracts\Source;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\SystemDto;
use App\Sources\Registry as SourceRegistry;

/**
 * Syncs SoftwareSystem/SecurityContainer inventory from every enabled Source
 * (Source::fetchSystems()/fetchContainers()) and every enabled Source Control
 * provider that implements EnumeratesInventory (fetchProjects()/fetchRepositories()),
 * through the shared SystemContainerUpserter — the same convergence point
 * FetchSourceJob and the assets:sync-azdo-projects command use.
 */
final class InventorySyncService
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly SourceControlRegistry $sourceControls,
        private readonly SystemIntegrationRuntime $runtime,
        private readonly SystemContainerUpserter $upserter,
        private readonly AzDoProjectLinker $linker,
        private readonly StaleRecordSweeper $sweeper,
    ) {}

    /**
     * @return array{
     *     projects_seen: int, systems_created: int, systems_updated: int, assets_created: int,
     *     repos_seen: int, containers_created: int, containers_updated: int, repository_mappings_created: int,
     * }
     */
    public function sync(?string $onlyId = null, ?string $projectFilter = null, ?string $repoFilter = null): array
    {
        $counts = [
            'projects_seen' => 0,
            'systems_created' => 0,
            'systems_updated' => 0,
            'assets_created' => 0,
            'repos_seen' => 0,
            'containers_created' => 0,
            'containers_updated' => 0,
            'repository_mappings_created' => 0,
        ];

        // A filtered pass is deliberately partial (an operator narrowing scope), not "this is
        // everything" — sweeping against a filtered touched-set would wrongly mark every
        // filtered-out project/repo as removed, so only sweep on a genuinely full pass.
        $shouldSweep = $projectFilter === null && $repoFilter === null;

        foreach ($this->sources->all() as $source) {
            if ($onlyId !== null && $source->id() !== $onlyId) {
                continue;
            }

            // Only sync integrations that are actually configured — a full sweep must not
            // hard-fail on a registered-but-uncredentialed Source.
            if (! $this->runtime->hasRequiredSystemCredentials($source->credentialFields())) {
                continue;
            }

            $this->runtime->runSource($source->id(), function (Source $resolvedSource) use ($projectFilter, $repoFilter, $shouldSweep, &$counts): void {
                $touched = $this->syncOne(
                    $resolvedSource->id(),
                    $resolvedSource->fetchSystems(),
                    fn (SystemDto $system): iterable => $resolvedSource->fetchContainers($system),
                    $projectFilter,
                    $repoFilter,
                    $counts,
                );

                if ($shouldSweep) {
                    $this->sweeper->sweepSystems($resolvedSource->id(), $touched['systemIds']);
                    $this->sweeper->sweepContainers($resolvedSource->id(), $touched['containerIds']);
                }
            });
        }

        foreach ($this->sourceControls->all() as $provider) {
            if (! $provider instanceof EnumeratesInventory) {
                continue;
            }

            if ($onlyId !== null && $provider->id() !== $onlyId) {
                continue;
            }

            if (! $this->runtime->hasRequiredSystemCredentials($provider->credentialFields())) {
                continue;
            }

            $this->runtime->runSourceControl($provider->id(), function (SourceControlProvider $resolvedProvider) use ($projectFilter, $repoFilter, $shouldSweep, &$counts): void {
                if (! $resolvedProvider instanceof EnumeratesInventory) {
                    return;
                }

                $touched = $this->syncOne(
                    $resolvedProvider->id(),
                    $resolvedProvider->fetchProjects(),
                    fn (SystemDto $project): iterable => $resolvedProvider->fetchRepositories($project),
                    $projectFilter,
                    $repoFilter,
                    $counts,
                );

                if ($shouldSweep) {
                    $this->sweeper->sweepSystems($resolvedProvider->id(), $touched['systemIds']);
                    $this->sweeper->sweepContainers($resolvedProvider->id(), $touched['containerIds']);
                }
            });
        }

        return $counts;
    }

    /**
     * @param  iterable<SystemDto>  $systemDtos
     * @param  callable(SystemDto): iterable<ContainerDto>  $fetchContainers
     * @param  array{
     *     projects_seen: int, systems_created: int, systems_updated: int, assets_created: int,
     *     repos_seen: int, containers_created: int, containers_updated: int, repository_mappings_created: int,
     * }  $counts
     * @return array{systemIds: list<int>, containerIds: list<int>}
     */
    private function syncOne(string $id, iterable $systemDtos, callable $fetchContainers, ?string $projectFilter, ?string $repoFilter, array &$counts): array
    {
        $systemIds = [];
        $containerIds = [];

        foreach ($systemDtos as $systemDto) {
            if (! self::matches($projectFilter, $systemDto->name)) {
                continue;
            }

            $counts['projects_seen']++;

            ['system' => $system, 'wasCreated' => $systemIsNew] = $this->upserter->upsertSystem($id, $systemDto);
            $counts[$systemIsNew ? 'systems_created' : 'systems_updated']++;
            $systemIds[] = $system->id;

            $hadAsset = $system->software_asset_id !== null;
            $this->linker->linkSystemToAsset($system);
            if (! $hadAsset && $system->refresh()->software_asset_id !== null) {
                $counts['assets_created']++;
            }

            foreach ($fetchContainers($systemDto) as $containerDto) {
                if (! self::matches($repoFilter, $containerDto->name)) {
                    continue;
                }

                $counts['repos_seen']++;

                ['container' => $container, 'wasCreated' => $containerIsNew] = $this->upserter->upsertContainer($system, $containerDto);
                $counts[$containerIsNew ? 'containers_created' : 'containers_updated']++;
                $containerIds[] = $container->id;

                $hadMapping = $container->repositoryMappings()->exists();
                $this->linker->ensureRepositoryMapping($container);
                if (! $hadMapping && $container->repositoryMappings()->exists()) {
                    $counts['repository_mappings_created']++;
                }
            }
        }

        return ['systemIds' => $systemIds, 'containerIds' => $containerIds];
    }

    private static function matches(?string $pattern, string $value): bool
    {
        if (! is_string($pattern) || $pattern === '') {
            return true;
        }

        return @preg_match('~' . str_replace('~', '\~', $pattern) . '~', $value) === 1;
    }
}
