<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

final class ReconciliationSummaryWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User ? ($user->can('admin.queue') || $user->can('work-items.sync')) : false;
    }

    protected function getStats(): array
    {
        $timestampRaw = Cache::get('reconciliation:last_run_at');
        $linksCreated = (int) Cache::get('reconciliation:last_run_new_links', 0);

        if (! is_string($timestampRaw) || trim($timestampRaw) === '') {
            return [
                Stat::make('Reconciliation', 'Never')
                    ->description(sprintf('%d new link(s) created', $linksCreated))
                    ->color('gray')
                    ->icon('heroicon-o-arrow-path'),
            ];
        }

        $timestamp = Carbon::parse($timestampRaw);

        return [
            Stat::make('Reconciliation', $timestamp->toDayDateTimeString())
                ->description(sprintf('%d new link(s) created', $linksCreated))
                ->color('success')
                ->icon('heroicon-o-arrow-path'),
        ];
    }
}
