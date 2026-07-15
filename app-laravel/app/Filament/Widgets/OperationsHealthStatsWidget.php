<?php

namespace App\Filament\Widgets;

use App\Models\SyncRun;
use App\Models\User;
use App\Queue\QueueRuntimeInspector;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OperationsHealthStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

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
        $running = (int) SyncRun::query()->where('status', 'running')->count();
        $scheduled = 3; // Managed schedule entries: dispatch-due, prune-audit, prune-errors

        $stats[] = Stat::make('Queued jobs', $queued)
            ->description('Jobs waiting in the queue')
            ->color($queued > 50 ? 'warning' : 'success')
            ->icon('heroicon-o-queue-list');
        $stats[] = Stat::make('Failed jobs', $failed)
            ->description('Failed jobs needing attention')
            ->color($failed > 0 ? 'danger' : 'success')
            ->icon('heroicon-o-exclamation-triangle');
        $stats[] = Stat::make('Running syncs', $running)
            ->description('Active source sync processes')
            ->color($running > 0 ? 'info' : 'gray')
            ->icon('heroicon-o-arrow-path');
        $stats[] = Stat::make('Managed schedules', $scheduled)
            ->description('Registered schedule entries')
            ->color('gray')
            ->icon('heroicon-o-clock');

        return $stats;
    }

    private function reconciliationStat(): Stat
    {
        $timestampRaw = Cache::get('reconciliation:last_run_at');
        $linksCreated = (int) Cache::get('reconciliation:last_run_new_links', 0);

        if (! is_string($timestampRaw) || trim($timestampRaw) === '') {
            return Stat::make('Reconciliation', 'Never')
                ->description(sprintf('%d new link(s) created', $linksCreated))
                ->color('gray')
                ->icon('heroicon-o-arrow-path');
        }

        $timestamp = Carbon::parse($timestampRaw);

        return Stat::make('Reconciliation', $timestamp->toDayDateTimeString())
            ->description(sprintf('%d new link(s) created', $linksCreated))
            ->color('success')
            ->icon('heroicon-o-arrow-path');
    }

    private function inventorySyncStat(): Stat
    {
        $timestampRaw = Cache::get('inventory_sync:last_run_at');

        /** @var array<string, int> $counts */
        $counts = Cache::get('inventory_sync:last_run_counts', []);
        $systems = ($counts['systems_created'] ?? 0) + ($counts['systems_updated'] ?? 0);
        $containers = ($counts['containers_created'] ?? 0) + ($counts['containers_updated'] ?? 0);

        if (! is_string($timestampRaw) || trim($timestampRaw) === '') {
            return Stat::make('Inventory sync', 'Never')
                ->description('0 system(s), 0 container(s) synced')
                ->color('gray')
                ->icon('heroicon-o-square-3-stack-3d');
        }

        $timestamp = Carbon::parse($timestampRaw);

        return Stat::make('Inventory sync', $timestamp->toDayDateTimeString())
            ->description(sprintf('%d system(s), %d container(s) synced', $systems, $containers))
            ->color($systems === 0 && $containers === 0 ? 'warning' : 'success')
            ->icon('heroicon-o-square-3-stack-3d');
    }
}
