<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownPieChartWidget;

class AlertsByStateChartWidget extends BreakdownPieChartWidget
{
    public function getHeading(): ?string
    {
        return 'Alerts by State';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::stateBreakdown();
    }
}
