<?php

namespace App\Filament\Pages;

use App\Audit\Recorder;
use App\Filament\Widgets\OperationsHealthStatsWidget;
use App\Filament\Widgets\RecentErrorsTableWidget;
use App\Filament\Widgets\RecentFailedJobsTableWidget;
use App\Filament\Widgets\RecentSyncRunsTableWidget;
use App\Filament\Widgets\SbomScanStatusWidget;
use App\Integrations\DispatchDueIntegrations;
use App\Jobs\PruneAuditLogs;
use App\Jobs\PruneErrorLogs;
use App\Models\SyncRun;
use App\Models\User;
use App\Queue\QueueRuntimeInspector;
use App\SourceControl\Contracts\EnumeratesInventory;
use App\SourceControl\Registry as SourceControlRegistry;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
    public function getWidgets(): array
    {
        return [
            OperationsHealthStatsWidget::class,
            RecentSyncRunsTableWidget::class,
            RecentErrorsTableWidget::class,
            RecentFailedJobsTableWidget::class,
            SbomScanStatusWidget::class,
        ];
    }

    /** @return list<class-string> */
    public function getHeaderWidgets(): array
    {
        return $this->getWidgets();
    }

    public ?string $selectedSourceId = null;

    public ?string $selectedTrackerId = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            ? $user->can('admin.queue') || $user->can('work-items.sync')
            : false;
    }

    public function mount(): void
    {
        $this->selectedSourceId = $this->sourceOptions() !== [] ? array_key_first($this->sourceOptions()) : null;
        $this->selectedTrackerId = $this->trackerOptions() !== [] ? array_key_first($this->trackerOptions()) : null;
    }

    /** @return array<Action|ActionGroup> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('dispatchDueIntegrations')
                ->label('Dispatch due integrations')
                ->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->action(fn () => $this->dispatchDueIntegrationsNow()),

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

    public function queuedJobCount(): int
    {
        return app(QueueRuntimeInspector::class)->queuedCount();
    }

    public function runningSyncCount(): int
    {
        return (int) SyncRun::query()->where('status', 'running')->count();
    }

    public function failedJobCount(): int
    {
        return (int) DB::table('failed_jobs')->count();
    }

    /** @return list<array{id: string, cadence: string}> */
    public function scheduleEntries(): array
    {
        return [
            ['id' => 'integrations:dispatch-due', 'cadence' => 'Every minute'],
            ['id' => 'prune-audit-logs', 'cadence' => 'Daily'],
            ['id' => 'prune-error-logs', 'cadence' => 'Daily'],
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

    public function dispatchDueIntegrationsNow(): void
    {
        $count = app(DispatchDueIntegrations::class)->dispatchDue();

        app(Recorder::class)->recordAdminAction('operations.dispatch_due_integrations', ['count' => $count]);

        Notification::make()->title("Queued {$count} due integration job(s)")->success()->send();
    }

    public function dispatchSelectedSource(string $override = ''): void
    {
        $id = $override !== '' ? $override : ($this->selectedSourceId ?? '');

        if ($id === '') {
            Notification::make()->title('Select a source first')->warning()->send();

            return;
        }

        FetchSourceJob::dispatch($id);
        app(Recorder::class)->recordAdminAction('operations.dispatch_source_fetch', ['source_id' => $id]);

        Notification::make()->title('Source fetch queued')->success()->send();
    }

    public function dispatchSelectedTracker(string $override = ''): void
    {
        $id = $override !== '' ? $override : ($this->selectedTrackerId ?? '');

        if ($id === '') {
            Notification::make()->title('Select a tracker first')->warning()->send();

            return;
        }

        RefreshWorkItemsJob::dispatch($id);
        app(Recorder::class)->recordAdminAction('operations.dispatch_tracker_refresh', ['tracker_id' => $id]);

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

    public function reconciliationLastRunSummary(): string
    {
        $timestampRaw = Cache::get('reconciliation:last_run_at');
        $linksCreated = (int) Cache::get('reconciliation:last_run_new_links', 0);

        if (! is_string($timestampRaw) || trim($timestampRaw) === '') {
            return 'Reconciliation has not run successfully yet.';
        }

        $timestamp = Carbon::parse($timestampRaw);

        return sprintf(
            'Last run: %s (%d new link(s) created).',
            $timestamp->toDayDateTimeString(),
            $linksCreated,
        );
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

        if ($this->inventoryCapableProviderCount() === 0) {
            Notification::make()
                ->title('No enabled Source or Source Control provider can supply inventory. Enable one in Integration Settings first.')
                ->warning()
                ->send();

            return;
        }

        if ($this->isSyncInventoryQueued()) {
            Notification::make()->title('Inventory sync is already queued or running.')->info()->send();

            return;
        }

        SyncInventoryJob::dispatch();
        app(Recorder::class)->recordAdminAction('operations.sync_inventory');

        Notification::make()->title('Inventory sync started. You will see updated Systems/Containers when the job completes.')->success()->send();
    }

    private function inventoryCapableProviderCount(): int
    {
        $sourceControlCount = count(array_filter(
            app(SourceControlRegistry::class)->enabled(),
            fn ($provider): bool => $provider instanceof EnumeratesInventory,
        ));

        return count(app(SourceRegistry::class)->enabled()) + $sourceControlCount;
    }

    private function isSyncInventoryQueued(): bool
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%SyncInventoryJob%')
            ->exists();
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
