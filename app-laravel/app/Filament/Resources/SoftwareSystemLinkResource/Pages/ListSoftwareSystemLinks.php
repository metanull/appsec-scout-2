<?php

namespace App\Filament\Resources\SoftwareSystemLinkResource\Pages;

use App\Filament\Resources\SoftwareSystemLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSoftwareSystemLinks extends ListRecords
{
    protected static string $resource = SoftwareSystemLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
