<?php

namespace App\Filament\Resources\SoftwareAssetResource\Pages;

use App\Filament\Resources\SoftwareAssetResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSoftwareAsset extends ViewRecord
{
    protected static string $resource = SoftwareAssetResource::class;

    /** @return array<EditAction> */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
