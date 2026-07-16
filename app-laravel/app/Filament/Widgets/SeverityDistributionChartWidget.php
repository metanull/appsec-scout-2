<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\DashboardData;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

class SeverityDistributionChartWidget extends ChartWidget
{
    protected ?string $heading = 'Severity Distribution';

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return Auth::user()?->can('alerts.view') ?? false;
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
