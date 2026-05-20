<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\DashboardData;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class SecurityOverviewStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return Auth::user()?->can('alerts.view') ?? false;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $stats = DashboardData::stats();

        return [
            Stat::make('Open Alerts', (string) $stats['totalOpen'])
                ->description('Current open findings')
                ->color('danger'),
            Stat::make('Critical', (string) $stats['severities']['critical'])
                ->color('danger'),
            Stat::make('High', (string) $stats['severities']['high'])
                ->color('warning'),
            Stat::make('Medium', (string) $stats['severities']['medium'])
                ->color('info'),
            Stat::make('Low', (string) $stats['severities']['low'])
                ->color('gray'),
            Stat::make('Resolved', (string) $stats['states']['resolved'])
                ->description('Closed by remediation')
                ->color('success'),
        ];
    }
}
