<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\BreakdownPieChartWidget;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;

class OpenLocalFindingsBySeverityChartWidget extends BreakdownPieChartWidget
{
    protected static ?int $sort = -100;

    public function getHeading(): ?string
    {
        return 'Open Local Findings by Severity';
    }

    protected function rows(): array
    {
        return LocalFindingBreakdownData::openBySeverityBreakdown();
    }
}
