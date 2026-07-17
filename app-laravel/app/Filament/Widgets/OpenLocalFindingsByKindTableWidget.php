<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LocalFindingResource;
use App\Filament\Widgets\Support\BreakdownTableWidget;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;
use App\Models\Enums\EventState;

class OpenLocalFindingsByKindTableWidget extends BreakdownTableWidget
{
    protected static ?int $sort = -103;

    protected static ?string $heading = 'Open Local Findings by Kind';

    protected function labelColumnHeading(): string
    {
        return 'Kind';
    }

    protected function emptyStateNote(): string
    {
        return 'No open local findings.';
    }

    protected function rows(): array
    {
        return LocalFindingBreakdownData::openByKindBreakdown();
    }

    protected function rowUrl(array $row): ?string
    {
        return LocalFindingResource::filteredIndexUrl([
            'kind' => [$row['key']],
            'status' => [EventState::Open->value],
        ]);
    }
}
