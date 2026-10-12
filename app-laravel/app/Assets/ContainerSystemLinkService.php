<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves a container to another software system (a manual link that later fetches keep, see
 * SystemContainerUpserter) or back to the system its source reports it under. Its alerts move
 * with it and the findings and SBOM components it owns are re-stamped on the spot.
 */
final class ContainerSystemLinkService
{
    public function __construct(private readonly Recorder $recorder) {}

    public function relink(SecurityContainer $container, SoftwareSystem $target, ?User $author = null): ContainerLinkChange
    {
        $this->ensureMovable($container, $target);

        return $this->move($container, $target, 'security_container_relinked', $author);
    }

    public function reset(SecurityContainer $container, ?User $author = null): ContainerLinkChange
    {
        $source = $this->resetTarget($container);
        $this->ensureMovable($container, $source);

        return $this->move($container, $source, 'security_container_link_reset', $author);
    }

    /** Fails with the reason a move of $container to $target would be refused. */
    public function ensureMovable(SecurityContainer $container, SoftwareSystem $target): void
    {
        if ($target->getAttribute('removed_at') !== null) {
            throw new InvalidArgumentException("Software system #{$target->id} was removed from its source.");
        }

        if ($target->id === $container->software_system_id) {
            throw new InvalidArgumentException("Security container #{$container->id} is already linked to software system #{$target->id}.");
        }

        if ($container->source_software_system_id === null) {
            throw new InvalidArgumentException("Security container #{$container->id} has no source system, so its link cannot be managed.");
        }
    }

    /** The system a reset would move $container back to; fails when there is nothing to restore. */
    public function resetTarget(SecurityContainer $container): SoftwareSystem
    {
        if (! $container->isSystemOverridden()) {
            throw new InvalidArgumentException("Security container #{$container->id} already follows its source system.");
        }

        return $container->sourceSoftwareSystem
            ?? throw new InvalidArgumentException("Security container #{$container->id} has no source system to restore.");
    }

    private function move(SecurityContainer $container, SoftwareSystem $target, string $action, ?User $author): ContainerLinkChange
    {
        return DB::transaction(function () use ($container, $target, $action, $author): ContainerLinkChange {
            $previousSystemId = $container->software_system_id;
            $overridden = $target->id !== $container->source_software_system_id;

            $container->forceFill([
                'software_system_id' => $target->id,
                'system_overridden_at' => $overridden ? now() : null,
            ])->save();

            $change = new ContainerLinkChange(
                container: $container->refresh(),
                previousSystemId: $previousSystemId,
                events: SecurityEvent::query()
                    ->where('container_id', $container->id)
                    ->toBase()
                    ->update(['software_system_id' => $target->id]),
                localFindings: $this->restamp(LocalFinding::query(), $container, $target),
                softwareComponents: $this->restamp(SoftwareComponent::query(), $container, $target),
            );

            $this->recorder->recordAdminAction($action, [
                'security_container_id' => $container->id,
                'previous_software_system_id' => $previousSystemId,
                'software_system_id' => $target->id,
                'source_software_system_id' => $container->source_software_system_id,
                'overridden' => $overridden,
                'author_id' => $author?->id,
                'events' => $change->events,
                'local_findings' => $change->localFindings,
                'software_components' => $change->softwareComponents,
            ]);

            return $change;
        });
    }

    /**
     * Owned rows carry denormalized system and asset ids, stamped at ingest: re-point the ones
     * that differ from the target.
     *
     * @param  Builder<LocalFinding>|Builder<SoftwareComponent>  $query
     */
    private function restamp(Builder $query, SecurityContainer $container, SoftwareSystem $target): int
    {
        $assetId = $target->software_asset_id;

        return $query
            ->whereMorphedTo('owner', $container)
            ->where(function (Builder $stale) use ($target, $assetId): void {
                $stale->whereNull('software_system_id')->orWhere('software_system_id', '!=', $target->id);

                if ($assetId === null) {
                    $stale->orWhereNotNull('software_asset_id');

                    return;
                }

                $stale->orWhereNull('software_asset_id')->orWhere('software_asset_id', '!=', $assetId);
            })
            ->toBase()
            ->update(['software_system_id' => $target->id, 'software_asset_id' => $assetId]);
    }
}
