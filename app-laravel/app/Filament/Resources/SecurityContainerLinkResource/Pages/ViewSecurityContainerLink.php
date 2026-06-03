<?php

namespace App\Filament\Resources\SecurityContainerLinkResource\Pages;

use App\Filament\Resources\SecurityContainerLinkResource;
use App\Filament\Resources\SecurityEventResource;
use App\Models\SecurityContainerLink;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSecurityContainerLink extends ViewRecord
{
    protected static string $resource = SecurityContainerLinkResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('viewAlerts')
                ->label('View alerts')
                ->url(fn (): string => SecurityEventResource::getUrl('index', [
                    'tableFilters' => [
                        'container_scope' => ['values' => ['virtual:' . $this->linkRecord()->id]],
                    ],
                ])),
        ];
    }

    private function linkRecord(): SecurityContainerLink
    {
        /** @var SecurityContainerLink $record */
        $record = $this->getRecord();

        return $record;
    }
}
