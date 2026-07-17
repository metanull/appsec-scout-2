<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownPieChartWidget;

class OpenAlertsBySystemChartWidget extends BreakdownPieChartWidget
{
    protected static ?int $sort = -111;

    public function getHeading(): ?string
    {
        return 'Open Alerts by Software System';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::openBySystemBreakdown();
    }
}
