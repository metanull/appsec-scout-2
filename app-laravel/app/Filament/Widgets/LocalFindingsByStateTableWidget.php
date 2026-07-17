<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LocalFindingResource;
use App\Filament\Widgets\Support\BreakdownTableWidget;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;
use App\Models\Enums\EventState;

class LocalFindingsByStateTableWidget extends BreakdownTableWidget
{
    protected static ?string $heading = 'Local Findings by State';

    protected function labelColumnHeading(): string
    {
        return 'Status';
    }

    protected function emptyStateNote(): string
    {
        return 'No local findings recorded.';
    }

    protected function rows(): array
    {
        return LocalFindingBreakdownData::stateBreakdown();
    }

    protected function rowUrl(array $row): ?string
    {
        return match ($row['key']) {
            EventState::Open->value => LocalFindingResource::filteredIndexUrl(['status' => [EventState::Open->value]]),
            EventState::InProgress->value => LocalFindingResource::filteredIndexUrl(['status' => [EventState::InProgress->value]]),
            'closed' => LocalFindingResource::filteredIndexUrl(['status' => [
                EventState::Acknowledged->value,
                EventState::Resolved->value,
                EventState::Dismissed->value,
            ]]),
            default => null,
        };
    }
}
