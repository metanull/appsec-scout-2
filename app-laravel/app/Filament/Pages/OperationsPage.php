<?php

namespace App\Filament\Pages;

use App\Audit\Recorder;
use App\Filament\Widgets\OperationsHealthStatsWidget;
use App\Filament\Widgets\RecentErrorsTableWidget;
use App\Filament\Widgets\RecentFailedJobsTableWidget;
use App\Filament\Widgets\RecentRepositoryCollectionRunsTableWidget;
use App\Filament\Widgets\RecentStaticAnalysisRunsTableWidget;
use App\Filament\Widgets\RecentSyncRunsTableWidget;
use App\Jobs\PruneAuditLogs;
use App\Jobs\PruneErrorLogs;
use App\Models\RepositoryCollectionRun;
use App\Models\StaticAnalysisRun;
use App\Models\User;
use App\SourceControl\Collection\DispatchRepositoryCollectionRunsJob;
use App\SourceControl\Collection\DispatchStaticAnalysisRunsJob;
use App\Sources\Registry as SourceRegistry;
use App\Sync\FetchSourceJob;
use App\Sync\SyncInventoryJob;
use App\Trackers\ReconcileAllJob;
use App\Trackers\RefreshWorkItemsJob;
use App\Trackers\Registry as TrackerRegistry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OperationsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Operations';

    protected static ?string $slug = 'admin/operations';

    /** @return list<class-string> */
    public function getHeaderWidgets(): array
    {
        return [OperationsHealthStatsWidget::class];
    }

    /** @return int|array<string, ?int> */
    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Operations')
                ->tabs([
                    Tab::make('Activity')
                        ->icon('heroicon-o-arrow-path')
                        ->schema(fn (): array => $this->getWidgetsSchemaComponents([
                            RecentSyncRunsTableWidget::class,
                            RecentRepositoryCollectionRunsTableWidget::class,
                            RecentStaticAnalysisRunsTableWidget::class,
                        ])),
                    Tab::make('Problems')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->schema(fn (): array => $this->getWidgetsSchemaComponents([
                            RecentErrorsTableWidget::class,
                            RecentFailedJobsTableWidget::class,
                        ])),
                ]),
        ]);
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            ? $user->can('admin.queue') || $user->can('work-items.sync')
            : false;
    }

    /** @return array<Action|ActionGroup> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetchSource')
                ->label('Fetch source')
                ->icon('heroicon-o-arrow-down-tray')
                ->form([
                    Select::make('source_id')
                        ->label('Source')
                        ->options(fn (): array => $this->sourceOptions())
                        ->required(),
                ])
                ->action(fn (array $data) => $this->dispatchSelectedSource($data['source_id'])),

            Action::make('refreshTracker')
                ->label('Refresh tracker')
                ->icon('heroicon-o-arrow-path')
                ->form([
                    Select::make('tracker_id')
                        ->label('Tracker')
                        ->options(fn (): array => $this->trackerOptions())
                        ->required(),
                ])
                ->action(fn (array $data) => $this->dispatchSelectedTracker($data['tracker_id'])),

            Action::make('reconcileWorkItems')
                ->label('Reconcile all tracker links')
                ->icon('heroicon-o-arrows-pointing-in')
                ->visible(fn (): bool => Gate::allows('admin.queue') || Gate::allows('work-items.sync'))
                ->requiresConfirmation()
                ->modalDescription('Queue a global reconciliation run to discover and link existing tracker work items.')
                ->action(fn () => $this->dispatchReconcileAll()),

            Action::make('syncInventory')
                ->label('Sync inventory')
                ->icon('heroicon-o-square-3-stack-3d')
                ->visible(fn (): bool => Gate::allows('admin.queue'))
                ->requiresConfirmation()
                ->modalDescription('Queue a sync of Systems/Containers from every enabled Source and Source Control provider.')
                ->action(fn () => $this->dispatchSyncInventory()),

            Action::make('collectRepositories')
                ->label('Collect repositories')
                ->icon('heroicon-o-code-bracket-square')
                ->visible(fn (): bool => Gate::allows('admin.queue'))
                ->requiresConfirmation()
                ->modalDescription('Queue an SBOM/vulnerability/secret collection sweep across every Azure DevOps repository the azdo-repos credential can see.')
                ->action(fn () => $this->dispatchCollectRepositories()),

            Action::make('runStaticAnalysis')
                ->label('Run static analysis')
                ->icon('heroicon-o-magnifying-glass-circle')
                ->visible(fn (): bool => Gate::allows('admin.queue'))
                ->requiresConfirmation()
                ->modalDescription('Queue a static analysis sweep (Roslynator for .NET, SpotBugs + Find Security Bugs for Java) across every Azure DevOps repository the azdo-repos credential can see.')
                ->action(fn () => $this->dispatchRunStaticAnalysis()),

            ActionGroup::make([
                Action::make('pruneAuditLogs')
                    ->label('Prune audit logs')
                    ->requiresConfirmation()
                    ->action(fn () => $this->pruneAuditLogsNow()),

                Action::make('pruneErrorLogs')
                    ->label('Prune error logs')
                    ->requiresConfirmation()
                    ->action(fn () => $this->pruneErrorLogsNow()),
            ])->label('Maintenance'),
        ];
    }

    /** @return array<string, string> */
    public function sourceOptions(): array
    {
        $options = [];

        foreach (app(SourceRegistry::class)->all() as $source) {
            $options[$source->id()] = $source->displayName();
        }

        return $options;
    }

    /** @return array<string, string> */
    public function trackerOptions(): array
    {
        $options = [];

        foreach (app(TrackerRegistry::class)->all() as $tracker) {
            $options[$tracker->id()] = $tracker->displayName();
        }

        return $options;
    }

    public function dispatchSelectedSource(string $sourceId): void
    {
        if ($sourceId === '') {
            Notification::make()->title('Select a source first')->warning()->send();

            return;
        }

        FetchSourceJob::dispatch($sourceId);
        app(Recorder::class)->recordAdminAction('operations.dispatch_source_fetch', ['source_id' => $sourceId]);

        Notification::make()->title('Source fetch queued')->success()->send();
    }

    public function dispatchSelectedTracker(string $trackerId): void
    {
        if ($trackerId === '') {
            Notification::make()->title('Select a tracker first')->warning()->send();

            return;
        }

        RefreshWorkItemsJob::dispatch($trackerId);
        app(Recorder::class)->recordAdminAction('operations.dispatch_tracker_refresh', ['tracker_id' => $trackerId]);

        Notification::make()->title('Tracker refresh queued')->success()->send();
    }

    public function dispatchReconcileAll(): void
    {
        Gate::authorize('work-items.sync');

        if ($this->isReconcileAllQueued()) {
            Notification::make()->title('Reconciliation is already queued or running.')->info()->send();

            return;
        }

        ReconcileAllJob::dispatch();
        app(Recorder::class)->recordAdminAction('operations.reconcile_work_items');

        Notification::make()->title('Reconciliation started. You will see new work item links when the job completes.')->success()->send();
    }

    private function isReconcileAllQueued(): bool
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%ReconcileAllJob%')
            ->exists();
    }

    public function dispatchSyncInventory(): void
    {
        Gate::authorize('admin.queue');

        if ($this->isSyncInventoryQueued()) {
            Notification::make()->title('Inventory sync is already queued or running.')->info()->send();

            return;
        }

        SyncInventoryJob::dispatch();
        app(Recorder::class)->recordAdminAction('operations.sync_inventory');

        Notification::make()->title('Inventory sync started. You will see updated Systems/Containers when the job completes.')->success()->send();
    }

    private function isSyncInventoryQueued(): bool
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%SyncInventoryJob%')
            ->exists();
    }

    public function dispatchCollectRepositories(): void
    {
        Gate::authorize('admin.queue');

        if (RepositoryCollectionRun::query()->where('status', 'running')->exists()) {
            Notification::make()->title('A repository collection run is already in progress.')->info()->send();

            return;
        }

        DispatchRepositoryCollectionRunsJob::dispatch();
        app(Recorder::class)->recordAdminAction('operations.collect_repositories');

        Notification::make()->title('Repository collection started. Progress appears in "Recent repository collection runs" below.')->success()->send();
    }

    public function dispatchRunStaticAnalysis(): void
    {
        Gate::authorize('admin.queue');

        if (StaticAnalysisRun::query()->where('status', 'running')->exists()) {
            Notification::make()->title('A static analysis run is already in progress.')->info()->send();

            return;
        }

        DispatchStaticAnalysisRunsJob::dispatch();
        app(Recorder::class)->recordAdminAction('operations.run_static_analysis');

        Notification::make()->title('Static analysis started. Progress appears in "Recent static analysis runs" below.')->success()->send();
    }

    public function pruneAuditLogsNow(): void
    {
        (new PruneAuditLogs((int) config('audit.retain_days', 365)))->handle();
        app(Recorder::class)->recordAdminAction('operations.prune_audit_logs');

        Notification::make()->title('Audit logs pruned')->success()->send();
    }

    public function pruneErrorLogsNow(): void
    {
        (new PruneErrorLogs((int) config('logging.error_retain_days', 90)))->handle();
        app(Recorder::class)->recordAdminAction('operations.prune_error_logs');

        Notification::make()->title('Error logs pruned')->success()->send();
    }
}
