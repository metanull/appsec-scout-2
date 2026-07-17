<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\BreakdownPieChartWidget;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;

class OpenLocalFindingsBySystemChartWidget extends BreakdownPieChartWidget
{
    public function getHeading(): ?string
    {
        return 'Open Local Findings by Software System';
    }

    protected function rows(): array
    {
        return LocalFindingBreakdownData::openBySystemBreakdown();
    }
}
