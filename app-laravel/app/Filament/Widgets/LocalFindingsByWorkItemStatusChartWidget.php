<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\BreakdownPieChartWidget;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;

class LocalFindingsByWorkItemStatusChartWidget extends BreakdownPieChartWidget
{
    protected static ?int $sort = -114;

    public function getHeading(): ?string
    {
        return 'Local Findings by Work Item status';
    }

    protected function rows(): array
    {
        return LocalFindingBreakdownData::workItemStatusBreakdown();
    }
}
