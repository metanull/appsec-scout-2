<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\BreakdownPieChartWidget;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;

class OpenLocalFindingsByKindChartWidget extends BreakdownPieChartWidget
{
    protected static ?int $sort = -104;

    public function getHeading(): ?string
    {
        return 'Open Local Findings by Kind';
    }

    protected function rows(): array
    {
        return LocalFindingBreakdownData::openByKindBreakdown();
    }
}
