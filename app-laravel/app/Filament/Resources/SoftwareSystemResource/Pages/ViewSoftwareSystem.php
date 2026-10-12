<?php

namespace App\Filament\Resources\SoftwareSystemResource\Pages;

use App\Audit\Recorder;
use App\Filament\Resources\SoftwareSystemResource;
use App\Filament\Support\OperationsAuthorization;
use App\Models\SoftwareSystem;
use App\Sync\FetchSystemJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSoftwareSystem extends ViewRecord
{
    protected static string $resource = SoftwareSystemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetchFromSource')
                ->label('Fetch from source')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->system()->getAttribute('removed_at') === null && OperationsAuthorization::canQueueOrSync())
                ->requiresConfirmation()
                ->modalDescription('Queue a fetch of this system\'s containers and alerts from its source, using the system credential. Other systems of the source are not touched.')
                ->action(fn () => $this->dispatchFetchFromSource()),
        ];
    }

    public function dispatchFetchFromSource(): void
    {
        OperationsAuthorization::authorizeQueueOrSync();

        $system = $this->system();

        FetchSystemJob::dispatch($system->id);
        app(Recorder::class)->recordAdminAction('operations.dispatch_system_fetch', [
            'software_system_id' => $system->id,
            'source_id' => $system->source_id,
        ]);

        Notification::make()->title("Fetch from {$system->source_id} queued for {$system->name}")->success()->send();
    }

    private function system(): SoftwareSystem
    {
        /** @var SoftwareSystem $system */
        $system = $this->getRecord();

        return $system;
    }
}
