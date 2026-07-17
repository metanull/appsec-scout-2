<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownPieChartWidget;

class OpenAlertsBySourceChartWidget extends BreakdownPieChartWidget
{
    protected static ?int $sort = -107;

    public function getHeading(): ?string
    {
        return 'Open Alerts by Source';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::openBySourceBreakdown();
    }
}
