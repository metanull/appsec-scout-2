<?php

namespace App\Filament\Resources\SoftwareAssetResource\Pages;

use App\Filament\Resources\SoftwareAssetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSoftwareAsset extends EditRecord
{
    protected static string $resource = SoftwareAssetResource::class;

    /** @return array<DeleteAction> */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
