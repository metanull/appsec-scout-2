<?php

namespace App\Sync;

use App\Audit\Recorder;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\SourceControl\Contracts\EnumeratesInventory;
use App\SourceControl\Registry as SourceControlRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Folds the systems and containers a Source Control provider stored under its own source id into
 * the rows of the Source it shares them with (EnumeratesInventory::inventorySourceId()). Before
 * that method existed, every inventory sync gave each AzDO project and repository a second,
 * empty system and container under `azdo-repos`, next to the AzDO source's own.
 *
 * A duplicate with a twin is merged: its activity columns (disabled flag, last commit), operator
 * links and asset link move to the twin, then the duplicate is deleted. A duplicate without a
 * twin is re-keyed to the Source's id. A duplicate that holds alerts, findings, components or
 * attachments is never deleted: it is reported for an operator to look at.
 */
final class SourceControlInventoryMerger
{
    public function __construct(
        private readonly SourceControlRegistry $sourceControls,
        private readonly Recorder $recorder,
    ) {}

    /**
     * @return array{systems_merged: int, systems_rekeyed: int, containers_merged: int, containers_moved: int, skipped: list<string>}
     */
    public function merge(bool $dryRun): array
    {
        $result = ['systems_merged' => 0, 'systems_rekeyed' => 0, 'containers_merged' => 0, 'containers_moved' => 0, 'skipped' => []];

        DB::beginTransaction();

        try {
            foreach ($this->sourceControls->all() as $provider) {
                if (! $provider instanceof EnumeratesInventory || $provider->inventorySourceId() === $provider->id()) {
                    continue;
                }

                $duplicates = SoftwareSystem::query()->where('source_id', $provider->id())->orderBy('id')->get();

                foreach ($duplicates as $duplicate) {
                    $this->mergeSystem($duplicate, $provider->inventorySourceId(), $result);
                }
            }

            if (! $dryRun && $this->changed($result)) {
                $this->recorder->recordAdminAction('source_control_inventory_duplicates_merged', array_merge($result, [
                    'skipped' => array_slice($result['skipped'], 0, 100),
                ]));
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $result;
    }

    /** @param  array{systems_merged: int, systems_rekeyed: int, containers_merged: int, containers_moved: int, skipped: list<string>}  $result */
    private function mergeSystem(SoftwareSystem $duplicate, string $sourceId, array &$result): void
    {
        $twin = SoftwareSystem::query()->where('source_id', $sourceId)->where('source_system_id', $duplicate->source_system_id)->first();

        if ($twin === null) {
            $duplicate->update(['source_id' => $sourceId]);
            $result['systems_rekeyed']++;

            return;
        }

        $containers = SecurityContainer::query()->where('source_software_system_id', $duplicate->id)->orderBy('id')->get();

        foreach ($containers as $container) {
            $this->mergeContainer($container, $twin, $result);
        }

        if ($duplicate->containers()->exists() || $this->systemHoldsData($duplicate)) {
            $result['skipped'][] = "system #{$duplicate->id} ({$duplicate->name}): still holds containers, alerts, findings, components or attachments";

            return;
        }

        $this->moveLinks($duplicate->trackerProjectLinks(), $twin);
        $this->moveLinks($duplicate->curatedLinks(), $twin);
        if (! $twin->repositoryMappings()->exists()) {
            $this->moveLinks($duplicate->repositoryMappings(), $twin);
        }

        if ($twin->software_asset_id === null && $duplicate->software_asset_id !== null) {
            $twin->update(['software_asset_id' => $duplicate->software_asset_id]);
        }

        $duplicate->delete();
        $result['systems_merged']++;
    }

    /** @param  array{systems_merged: int, systems_rekeyed: int, containers_merged: int, containers_moved: int, skipped: list<string>}  $result */
    private function mergeContainer(SecurityContainer $container, SoftwareSystem $twinSystem, array &$result): void
    {
        $twin = SecurityContainer::query()
            ->where('source_software_system_id', $twinSystem->id)
            ->where('source_container_id', $container->source_container_id)
            ->first();

        if ($twin === null) {
            $container->source_software_system_id = $twinSystem->id;
            if (! $container->isSystemOverridden()) {
                $container->software_system_id = $twinSystem->id;
            }
            $container->save();
            $result['containers_moved']++;

            return;
        }

        if ($this->containerHoldsData($container)) {
            $result['skipped'][] = "container #{$container->id} ({$container->name}): holds alerts, findings, components or attachments";

            return;
        }

        // The Source never recorded these before inventorySourceId(); the duplicate did.
        $twin->fill([
            'is_disabled' => $container->is_disabled,
            'last_commit_sha' => $container->last_commit_sha ?? $twin->last_commit_sha,
            'last_commit_at' => $container->last_commit_at ?? $twin->last_commit_at,
            'last_commit_author' => $container->last_commit_author ?? $twin->last_commit_author,
        ])->save();

        $this->moveLinks($container->trackerProjectLinks(), $twin);
        $this->moveLinks($container->curatedLinks(), $twin);
        if (! $twin->repositoryMappings()->exists()) {
            $this->moveLinks($container->repositoryMappings(), $twin);
        }

        $container->delete();
        $result['containers_merged']++;
    }

    /** @param  MorphMany<covariant Model, covariant Model>  $links */
    private function moveLinks(MorphMany $links, Model $target): void
    {
        $links->toBase()->update(['owner_id' => $target->getKey()]);
    }

    private function containerHoldsData(SecurityContainer $container): bool
    {
        return $container->events()->exists()
            || $container->localFindings()->exists()
            || $container->softwareComponents()->exists()
            || $container->attachments()->exists()
            || $container->staticAnalysisState()->exists();
    }

    private function systemHoldsData(SoftwareSystem $system): bool
    {
        return $system->events()->exists()
            || $system->localFindings()->exists()
            || $system->softwareComponents()->exists()
            || $system->attachments()->exists();
    }

    /** @param  array{systems_merged: int, systems_rekeyed: int, containers_merged: int, containers_moved: int, skipped: list<string>}  $result */
    private function changed(array $result): bool
    {
        return $result['systems_merged'] + $result['systems_rekeyed'] + $result['containers_merged'] + $result['containers_moved'] > 0;
    }
}
