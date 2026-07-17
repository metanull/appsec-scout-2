<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownPieChartWidget;

class OpenAlertsByTypeChartWidget extends BreakdownPieChartWidget
{
    public function getHeading(): ?string
    {
        return 'Open Alerts by Type';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::openByTypeBreakdown();
    }
}
