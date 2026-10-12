<?php

namespace App\Filament\Resources\SoftwareSystemResource\Pages;

use App\Audit\Recorder;
use App\Filament\Resources\SoftwareSystemResource;
use App\Filament\Support\OperationsAuthorization;
use App\Models\RepositoryCollectionRun;
use App\Models\SoftwareSystem;
use App\SourceControl\Collection\DispatchRepositoryCollectionRunsJob;
use App\Sources\AzDo\AzDoNormalizer;
use App\Sync\FetchSystemJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

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

            Action::make('collectRepositories')
                ->label('Collect repositories')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->visible(fn (): bool => $this->isActiveAzDoSystem() && Gate::allows('admin.queue'))
                ->requiresConfirmation()
                ->modalDescription('Queue repository collection (clone + Trivy: SBOM, vulnerabilities, secrets) for this system\'s Azure DevOps repositories only. Only one collection run can be active at a time.')
                ->action(fn () => $this->dispatchCollectRepositories()),
        ];
    }

    public function dispatchCollectRepositories(): void
    {
        Gate::authorize('admin.queue');

        if (RepositoryCollectionRun::query()->where('status', 'running')->exists()) {
            Notification::make()->title('A repository collection run is already in progress.')->info()->send();

            return;
        }

        $system = $this->system();

        DispatchRepositoryCollectionRunsJob::dispatch($system->id);
        app(Recorder::class)->recordAdminAction('operations.collect_repositories', ['software_system_id' => $system->id]);

        Notification::make()->title("Repository collection queued for {$system->name}")->success()->send();
    }

    private function isActiveAzDoSystem(): bool
    {
        $system = $this->system();

        return $system->source_id === AzDoNormalizer::SOURCE_ID && $system->getAttribute('removed_at') === null;
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
