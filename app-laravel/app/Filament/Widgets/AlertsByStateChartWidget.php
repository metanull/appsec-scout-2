<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownPieChartWidget;

class AlertsByStateChartWidget extends BreakdownPieChartWidget
{
    protected static ?int $sort = -119;

    public function getHeading(): ?string
    {
        return 'Alerts by State';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::stateBreakdown();
    }
}
