<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownPieChartWidget;

class AlertsByWorkItemStatusChartWidget extends BreakdownPieChartWidget
{
    protected static ?int $sort = -115;

    public function getHeading(): ?string
    {
        return 'Alerts by Work Item status';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::workItemStatusBreakdown();
    }
}
