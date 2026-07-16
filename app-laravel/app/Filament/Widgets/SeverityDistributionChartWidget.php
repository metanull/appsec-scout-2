<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\DashboardData;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

class SeverityDistributionChartWidget extends ChartWidget
{
    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return Auth::user()?->can('alerts.view') ?? false;
    }

    public function getHeading(): ?string
    {
        $hasSeverities = array_sum(DashboardData::stats()['severities']) > 0;

        return $hasSeverities
            ? 'Severity Distribution'
            : 'Severity Distribution — no alerts recorded';
    }

    protected function getData(): array
    {
        return DashboardData::severityChart();
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
