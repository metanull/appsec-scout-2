<?php

namespace App\Filament\Resources\SoftwareSystemResource\Pages;

use App\Audit\Recorder;
use App\Filament\Resources\SoftwareSystemResource;
use App\Filament\Support\OperationsAuthorization;
use App\Models\RepositoryCollectionRun;
use App\Models\SoftwareSystem;
use App\Models\StaticAnalysisRun;
use App\SourceControl\Collection\DispatchRepositoryCollectionRunsJob;
use App\SourceControl\Collection\DispatchStaticAnalysisRunsJob;
use App\Sources\AzDo\AzDoNormalizer;
use App\Sync\FetchSystemJob;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
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

            Action::make('runStaticAnalysis')
                ->label('Run static analysis')
                ->icon('heroicon-o-magnifying-glass-circle')
                ->visible(fn (): bool => $this->isActiveAzDoSystem() && Gate::allows('admin.queue'))
                ->requiresConfirmation()
                ->modalDescription('Queue static analysis (Roslynator, SpotBugs + Find Security Bugs, Opengrep) for this system\'s Azure DevOps repositories only. Repositories whose head commit has not moved since their last clean analysis are skipped unless forced. Only one analysis run can be active at a time.')
                ->form([
                    Toggle::make('force')
                        ->label('Force full re-analysis')
                        ->helperText('Re-analyses every repository even when its head commit is unchanged.')
                        ->default(false),
                ])
                ->action(fn (array $data) => $this->dispatchRunStaticAnalysis((bool) ($data['force'] ?? false))),
        ];
    }

    public function dispatchRunStaticAnalysis(bool $force = false): void
    {
        Gate::authorize('admin.queue');

        if (StaticAnalysisRun::query()->where('status', 'running')->exists()) {
            Notification::make()->title('A static analysis run is already in progress.')->info()->send();

            return;
        }

        $system = $this->system();

        DispatchStaticAnalysisRunsJob::dispatch($force, $system->id);
        app(Recorder::class)->recordAdminAction('operations.run_static_analysis', [
            'force' => $force,
            'software_system_id' => $system->id,
        ]);

        Notification::make()->title("Static analysis queued for {$system->name}")->success()->send();
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
