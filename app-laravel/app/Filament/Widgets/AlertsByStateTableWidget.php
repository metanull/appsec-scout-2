<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownTableWidget;
use App\Models\Enums\EventState;

class AlertsByStateTableWidget extends BreakdownTableWidget
{
    protected static ?string $heading = 'Alerts by State';

    protected function labelColumnHeading(): string
    {
        return 'State';
    }

    protected function emptyStateNote(): string
    {
        return 'No alerts recorded.';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::stateBreakdown();
    }

    protected function rowUrl(array $row): ?string
    {
        return match ($row['key']) {
            EventState::Open->value => SecurityEventResource::filteredIndexUrl(['state' => [EventState::Open->value]]),
            EventState::InProgress->value => SecurityEventResource::filteredIndexUrl(['state' => [EventState::InProgress->value]]),
            'closed' => SecurityEventResource::filteredIndexUrl(['state' => [
                EventState::Acknowledged->value,
                EventState::Resolved->value,
                EventState::Dismissed->value,
            ]]),
            default => null,
        };
    }
}
