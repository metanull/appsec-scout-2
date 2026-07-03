<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SoftwareAssetService
{
    public function __construct(private readonly Recorder $recorder) {}

    public function attach(SoftwareAsset $asset, SoftwareSystem $system, ?User $author = null): SoftwareSystem
    {
        return DB::transaction(function () use ($asset, $system, $author): SoftwareSystem {
            $previousAssetId = $system->software_asset_id;

            $system->forceFill(['software_asset_id' => $asset->id])->save();

            $this->recorder->recordAdminAction('software_system_linked_to_asset', [
                'software_asset_id' => $asset->id,
                'software_system_id' => $system->id,
                'previous_software_asset_id' => $previousAssetId,
                'author_id' => $author?->id,
            ]);

            return $system->refresh();
        });
    }

    public function detach(SoftwareSystem $system, ?User $author = null): SoftwareSystem
    {
        return DB::transaction(function () use ($system, $author): SoftwareSystem {
            $previousAssetId = $system->software_asset_id;

            $system->forceFill(['software_asset_id' => null])->save();

            $this->recorder->recordAdminAction('software_system_unlinked_from_asset', [
                'software_system_id' => $system->id,
                'previous_software_asset_id' => $previousAssetId,
                'author_id' => $author?->id,
            ]);

            return $system->refresh();
        });
    }
}
