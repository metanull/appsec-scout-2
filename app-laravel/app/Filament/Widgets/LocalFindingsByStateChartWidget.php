<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\BreakdownPieChartWidget;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;

class LocalFindingsByStateChartWidget extends BreakdownPieChartWidget
{
    protected static ?int $sort = -118;

    public function getHeading(): ?string
    {
        return 'Local Findings by State';
    }

    protected function rows(): array
    {
        return LocalFindingBreakdownData::stateBreakdown();
    }
}
