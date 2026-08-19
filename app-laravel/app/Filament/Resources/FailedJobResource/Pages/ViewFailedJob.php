<?php

namespace App\Filament\Resources\FailedJobResource\Pages;

use App\Filament\Resources\FailedJobResource;
use App\Models\FailedJob;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewFailedJob extends ViewRecord
{
    protected static string $resource = FailedJobResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->label('Retry')
                ->icon('heroicon-o-arrow-uturn-left')
                ->action(function (): void {
                    /** @var FailedJob $record */
                    $record = $this->getRecord();

                    FailedJobResource::notifyRetryResult(FailedJobResource::retryFailedJob($record->uuid));

                    $this->redirect(FailedJobResource::getUrl('index'));
                }),
            Action::make('forget')
                ->label('Forget')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var FailedJob $record */
                    $record = $this->getRecord();

                    FailedJobResource::notifyForgetResult(FailedJobResource::forgetFailedJob($record->uuid));

                    $this->redirect(FailedJobResource::getUrl('index'));
                }),
        ];
    }
}
