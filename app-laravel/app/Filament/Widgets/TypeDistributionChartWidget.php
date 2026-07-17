<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\DashboardData;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

class TypeDistributionChartWidget extends ChartWidget
{
    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        return Auth::user()?->can('alerts.view') ?? false;
    }

    public function getHeading(): ?string
    {
        $hasTypes = array_sum(DashboardData::typeChart()['datasets'][0]['data']) > 0;

        return $hasTypes
            ? 'Open Alerts by Type'
            : 'Open Alerts by Type — no alerts recorded';
    }

    protected function getData(): array
    {
        return DashboardData::typeChart();
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
