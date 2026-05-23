<?php

namespace App\Filament\Resources\SoftwareSystemLinkResource\Pages;

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SoftwareSystemLinkResource;
use App\Models\SoftwareSystemLink;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSoftwareSystemLink extends ViewRecord
{
    protected static string $resource = SoftwareSystemLinkResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('viewAlerts')
                ->label('View alerts')
                ->url(fn (): string => SecurityEventResource::getUrl('index', [
                    'tableFilters' => [
                        'system_scope' => ['values' => ['virtual:' . $this->linkRecord()->id]],
                    ],
                ])),
        ];
    }

    private function linkRecord(): SoftwareSystemLink
    {
        /** @var SoftwareSystemLink $record */
        $record = $this->getRecord();

        return $record;
    }
}
