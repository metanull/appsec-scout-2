<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\PendingJobsPage;
use App\Filament\Resources\FailedJobResource;
use App\Models\SyncRun;
use App\Models\User;
use App\Queue\QueueRuntimeInspector;
use App\Sync\InventorySyncService;
use App\Trackers\ReconcileAllJob;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OperationsHealthStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User ? ($user->can('admin.queue') || $user->can('work-items.sync')) : false;
    }

    protected function getStats(): array
    {
        $user = Auth::user();
        $canQueue = $user instanceof User && $user->can('admin.queue');
        $canSync = $user instanceof User && ($canQueue || $user->can('work-items.sync'));

        $stats = [];

        if ($canSync) {
            $stats[] = $this->reconciliationStat();
        }

        if (! $canQueue) {
            return $stats;
        }

        $stats[] = $this->inventorySyncStat();

        $queued = app(QueueRuntimeInspector::class)->queuedCount();
        $failed = (int) DB::table('failed_jobs')->count();

        $stats[] = Stat::make('Queued jobs', $queued)
            ->description('Jobs queued or currently running (the pending list below only shows jobs not yet started)')
            ->color($queued > 50 ? 'warning' : 'success')
            ->icon('heroicon-o-queue-list')
            ->url(PendingJobsPage::getUrl());
        $stats[] = Stat::make('Failed jobs', $failed)
            ->description('Failed jobs needing attention')
            ->color($failed > 0 ? 'danger' : 'success')
            ->icon('heroicon-o-exclamation-triangle')
            ->url(FailedJobResource::getUrl('index'));

        return $stats;
    }

    private function reconciliationStat(): Stat
    {
        $run = SyncRun::query()
            ->where('source_id', ReconcileAllJob::RUN_SOURCE_ID)
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->first();

        if ($run === null || $run->getRawOriginal('finished_at') === null) {
            return Stat::make('Reconciliation', 'Never')
                ->description('0 new link(s) created')
                ->color('gray')
                ->icon('heroicon-o-arrow-path');
        }

        $finishedAt = Carbon::parse((string) $run->finished_at)->toDayDateTimeString();

        // A failed sweep must be visible as such — the error message itself stays on the
        // run row and its ErrorLog rather than in the stat.
        if ($run->status === 'failure') {
            return Stat::make('Reconciliation', $finishedAt)
                ->description('Last run failed')
                ->color('danger')
                ->icon('heroicon-o-arrow-path');
        }

        $counts = $run->getAttribute('counts_json');
        $counts = is_array($counts) ? $counts : [];

        return Stat::make('Reconciliation', $finishedAt)
            ->description(sprintf('%d new link(s) created', (int) ($counts['links_created'] ?? 0)))
            ->color('success')
            ->icon('heroicon-o-arrow-path');
    }

    private function inventorySyncStat(): Stat
    {
        $run = SyncRun::query()
            ->where('source_id', InventorySyncService::RUN_SOURCE_ID)
            ->where('status', 'success')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->first();

        if ($run === null || $run->getRawOriginal('finished_at') === null) {
            return Stat::make('Inventory sync', 'Never')
                ->description('0 system(s), 0 container(s) synced')
                ->color('gray')
                ->icon('heroicon-o-square-3-stack-3d');
        }

        $counts = $run->getAttribute('counts_json');
        $counts = is_array($counts) ? $counts : [];
        $systems = (int) ($counts['systems_created'] ?? 0) + (int) ($counts['systems_updated'] ?? 0);
        $containers = (int) ($counts['containers_created'] ?? 0) + (int) ($counts['containers_updated'] ?? 0);

        return Stat::make('Inventory sync', Carbon::parse((string) $run->finished_at)->toDayDateTimeString())
            ->description(sprintf('%d system(s), %d container(s) synced', $systems, $containers))
            ->color($systems === 0 && $containers === 0 ? 'warning' : 'success')
            ->icon('heroicon-o-square-3-stack-3d');
    }
}
