<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownPieChartWidget;

class OpenAlertsBySystemChartWidget extends BreakdownPieChartWidget
{
    public function getHeading(): ?string
    {
        return 'Open Alerts by Software System';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::openBySystemBreakdown();
    }
}
