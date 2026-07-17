<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LocalFindingResource;
use App\Filament\Widgets\Support\BreakdownTableWidget;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;
use App\Models\Enums\EventState;

class OpenLocalFindingsBySeverityTableWidget extends BreakdownTableWidget
{
    protected static ?int $sort = -99;

    protected static ?string $heading = 'Open Local Findings by Severity';

    protected function labelColumnHeading(): string
    {
        return 'Severity';
    }

    protected function emptyStateNote(): string
    {
        return 'No open local findings.';
    }

    protected function rows(): array
    {
        return LocalFindingBreakdownData::openBySeverityBreakdown();
    }

    protected function rowUrl(array $row): ?string
    {
        return LocalFindingResource::filteredIndexUrl([
            'severity' => [$row['key']],
            'status' => [EventState::Open->value],
        ]);
    }
}
