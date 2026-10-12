<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Models\LocalFinding;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class SoftwareAssetService
{
    public function __construct(private readonly Recorder $recorder) {}

    public function attach(SoftwareAsset $asset, SoftwareSystem $system, ?User $author = null): SystemAssetLinkChange
    {
        return DB::transaction(function () use ($asset, $system, $author): SystemAssetLinkChange {
            $previousAssetId = $system->software_asset_id;

            $system->forceFill(['software_asset_id' => $asset->id])->save();
            $restamped = $this->restampHierarchy($system);

            $this->recorder->recordAdminAction('software_system_linked_to_asset', [
                'software_asset_id' => $asset->id,
                'software_system_id' => $system->id,
                'previous_software_asset_id' => $previousAssetId,
                'author_id' => $author?->id,
                'restamped' => $restamped,
            ]);

            return new SystemAssetLinkChange($system->refresh(), $previousAssetId, $restamped);
        });
    }

    public function detach(SoftwareSystem $system, ?User $author = null): SystemAssetLinkChange
    {
        return DB::transaction(function () use ($system, $author): SystemAssetLinkChange {
            $previousAssetId = $system->software_asset_id;

            $system->forceFill(['software_asset_id' => null])->save();
            $restamped = $this->restampHierarchy($system);

            $this->recorder->recordAdminAction('software_system_unlinked_from_asset', [
                'software_system_id' => $system->id,
                'previous_software_asset_id' => $previousAssetId,
                'author_id' => $author?->id,
                'restamped' => $restamped,
            ]);

            return new SystemAssetLinkChange($system->refresh(), $previousAssetId, $restamped);
        });
    }

    /**
     * Local findings and SBOM components carry a denormalized software_asset_id, stamped at
     * ingest from their system's asset. Re-point every row under this system at the system's
     * current asset so asset-scoped filters stay correct after a system is linked, moved or
     * unlinked. Only rows whose stamp actually differs are touched.
     *
     * @return array{local_findings: int, software_components: int}
     */
    public function restampHierarchy(SoftwareSystem $system): array
    {
        return [
            'local_findings' => $this->restamp(LocalFinding::query(), $system),
            'software_components' => $this->restamp(SoftwareComponent::query(), $system),
        ];
    }

    /** @param  Builder<LocalFinding>|Builder<SoftwareComponent>  $query */
    private function restamp(Builder $query, SoftwareSystem $system): int
    {
        $assetId = $system->software_asset_id;

        return $query
            ->where('software_system_id', $system->id)
            ->where(function (Builder $stale) use ($assetId): void {
                if ($assetId === null) {
                    $stale->whereNotNull('software_asset_id');

                    return;
                }

                $stale->whereNull('software_asset_id')->orWhere('software_asset_id', '!=', $assetId);
            })
            ->toBase()
            ->update(['software_asset_id' => $assetId]);
    }
}
