<?php

namespace App\Sync;

use App\Assets\AzDoProjectLinker;
use App\Integrations\SystemIntegrationRuntime;
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

        foreach ($this->sources->enabled() as $source) {
            if ($onlyId !== null && $source->id() !== $onlyId) {
                continue;
            }

            $this->runtime->runSource($source->id(), function (Source $resolvedSource) use ($projectFilter, $repoFilter, &$counts): void {
                $this->syncOne(
                    $resolvedSource->id(),
                    $resolvedSource->fetchSystems(),
                    fn (SystemDto $system): iterable => $resolvedSource->fetchContainers($system),
                    $projectFilter,
                    $repoFilter,
                    $counts,
                );
            });
        }

        foreach ($this->sourceControls->enabled() as $provider) {
            if (! $provider instanceof EnumeratesInventory) {
                continue;
            }

            if ($onlyId !== null && $provider->id() !== $onlyId) {
                continue;
            }

            $this->runtime->runSourceControl($provider->id(), function (SourceControlProvider $resolvedProvider) use ($projectFilter, $repoFilter, &$counts): void {
                if (! $resolvedProvider instanceof EnumeratesInventory) {
                    return;
                }

                $this->syncOne(
                    $resolvedProvider->id(),
                    $resolvedProvider->fetchProjects(),
                    fn (SystemDto $project): iterable => $resolvedProvider->fetchRepositories($project),
                    $projectFilter,
                    $repoFilter,
                    $counts,
                );
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
     */
    private function syncOne(string $id, iterable $systemDtos, callable $fetchContainers, ?string $projectFilter, ?string $repoFilter, array &$counts): void
    {
        foreach ($systemDtos as $systemDto) {
            if (! self::matches($projectFilter, $systemDto->name)) {
                continue;
            }

            $counts['projects_seen']++;

            ['system' => $system, 'wasCreated' => $systemIsNew] = $this->upserter->upsertSystem($id, $systemDto);
            $counts[$systemIsNew ? 'systems_created' : 'systems_updated']++;

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

                $hadMapping = $container->repositoryMappings()->exists();
                $this->linker->ensureRepositoryMapping($container);
                if (! $hadMapping && $container->repositoryMappings()->exists()) {
                    $counts['repository_mappings_created']++;
                }
            }
        }
    }

    private static function matches(?string $pattern, string $value): bool
    {
        if (! is_string($pattern) || $pattern === '') {
            return true;
        }

        return @preg_match('~' . str_replace('~', '\~', $pattern) . '~', $value) === 1;
    }
}
