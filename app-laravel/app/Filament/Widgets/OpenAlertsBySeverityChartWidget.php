<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownPieChartWidget;

class OpenAlertsBySeverityChartWidget extends BreakdownPieChartWidget
{
    public function getHeading(): ?string
    {
        return 'Open Alerts by Severity';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::openBySeverityBreakdown();
    }
}
