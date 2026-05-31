<?php

namespace App\Filament\Widgets;

use App\Models\SyncRun;
use App\Models\User;
use App\Queue\QueueRuntimeInspector;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OperationsHealthStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User ? $user->can('admin.queue') : false;
    }

    protected function getStats(): array
    {
        $queued = app(QueueRuntimeInspector::class)->queuedCount();
        $failed = (int) DB::table('failed_jobs')->count();
        $running = (int) SyncRun::query()->where('status', 'running')->count();
        $scheduled = 4; // Managed schedule entries: dispatch-due, prune-audit, prune-errors, update-trivy-db

        return [
            Stat::make('Queued jobs', $queued)
                ->description('Jobs waiting in the queue')
                ->color($queued > 50 ? 'warning' : 'success')
                ->icon('heroicon-o-queue-list'),
            Stat::make('Failed jobs', $failed)
                ->description('Failed jobs needing attention')
                ->color($failed > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),
            Stat::make('Running syncs', $running)
                ->description('Active source sync processes')
                ->color($running > 0 ? 'info' : 'gray')
                ->icon('heroicon-o-arrow-path'),
            Stat::make('Managed schedules', $scheduled)
                ->description('Registered schedule entries')
                ->color('gray')
                ->icon('heroicon-o-clock'),
        ];
    }
}
