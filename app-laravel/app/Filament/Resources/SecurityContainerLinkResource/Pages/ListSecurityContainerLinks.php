<?php

namespace App\Filament\Resources\SecurityContainerLinkResource\Pages;

use App\Filament\Resources\SecurityContainerLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSecurityContainerLinks extends ListRecords
{
    protected static string $resource = SecurityContainerLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
