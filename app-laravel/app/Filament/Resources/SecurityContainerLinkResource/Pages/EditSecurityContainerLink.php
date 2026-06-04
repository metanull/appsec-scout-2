<?php

namespace App\Filament\Resources\SecurityContainerLinkResource\Pages;

use App\Filament\Resources\SecurityContainerLinkResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSecurityContainerLink extends EditRecord
{
    protected static string $resource = SecurityContainerLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
