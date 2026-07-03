<?php

namespace App\Filament\Resources\SoftwareAssetResource\Pages;

use App\Filament\Resources\SoftwareAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSoftwareAssets extends ListRecords
{
    protected static string $resource = SoftwareAssetResource::class;

    /** @return array<CreateAction> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
