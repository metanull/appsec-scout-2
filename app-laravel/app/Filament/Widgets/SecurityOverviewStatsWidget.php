<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Widgets\Support\DashboardData;
use App\Models\Enums\EventState;
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

        $openStates = [
            EventState::Open->value,
            EventState::InProgress->value,
            EventState::Acknowledged->value,
        ];

        return [
            Stat::make('Open Alerts', (string) $stats['totalOpen'])
                ->description('Current open findings')
                ->color('danger')
                ->url(SecurityEventResource::filteredIndexUrl(['state' => $openStates])),
            Stat::make('Critical', (string) $stats['severities']['critical'])
                ->color('danger')
                ->url(SecurityEventResource::filteredIndexUrl(['severity' => ['critical']])),
            Stat::make('High', (string) $stats['severities']['high'])
                ->color('warning')
                ->url(SecurityEventResource::filteredIndexUrl(['severity' => ['high']])),
            Stat::make('Medium', (string) $stats['severities']['medium'])
                ->color('info')
                ->url(SecurityEventResource::filteredIndexUrl(['severity' => ['medium']])),
            Stat::make('Low', (string) $stats['severities']['low'])
                ->color('gray')
                ->url(SecurityEventResource::filteredIndexUrl(['severity' => ['low']])),
            Stat::make('Resolved', (string) $stats['states']['resolved'])
                ->description('Closed by remediation')
                ->color('success')
                ->url(SecurityEventResource::filteredIndexUrl(['state' => [EventState::Resolved->value]])),
        ];
    }
}
