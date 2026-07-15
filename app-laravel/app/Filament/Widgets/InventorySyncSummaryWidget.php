<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

final class InventorySyncSummaryWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User ? $user->can('admin.queue') : false;
    }

    protected function getStats(): array
    {
        $timestampRaw = Cache::get('inventory_sync:last_run_at');

        /** @var array<string, int> $counts */
        $counts = Cache::get('inventory_sync:last_run_counts', []);
        $systems = ($counts['systems_created'] ?? 0) + ($counts['systems_updated'] ?? 0);
        $containers = ($counts['containers_created'] ?? 0) + ($counts['containers_updated'] ?? 0);

        if (! is_string($timestampRaw) || trim($timestampRaw) === '') {
            return [
                Stat::make('Inventory sync', 'Never')
                    ->description('0 system(s), 0 container(s) synced')
                    ->color('gray')
                    ->icon('heroicon-o-square-3-stack-3d'),
            ];
        }

        $timestamp = Carbon::parse($timestampRaw);

        return [
            Stat::make('Inventory sync', $timestamp->toDayDateTimeString())
                ->description(sprintf('%d system(s), %d container(s) synced', $systems, $containers))
                ->color($systems === 0 && $containers === 0 ? 'warning' : 'success')
                ->icon('heroicon-o-square-3-stack-3d'),
        ];
    }
}
