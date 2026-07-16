<?php

namespace App\Filament\Resources\RepositoryProviderResource\Pages;

use App\Filament\Resources\RepositoryProviderResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRepositoryProvider extends ViewRecord
{
    protected static string $resource = RepositoryProviderResource::class;

    /** @return array<EditAction> */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
