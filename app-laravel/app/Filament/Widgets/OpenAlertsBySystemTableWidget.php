<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownTableWidget;
use App\Filament\Widgets\Support\OpenBySystemBreakdown;
use App\Models\Enums\EventState;

class OpenAlertsBySystemTableWidget extends BreakdownTableWidget
{
    protected static ?int $sort = -112;

    protected static ?string $heading = 'Open Alerts by Software System';

    protected function labelColumnHeading(): string
    {
        return 'System';
    }

    protected function emptyStateNote(): string
    {
        return 'No open alerts.';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::openBySystemBreakdown();
    }

    protected function rowUrl(array $row): ?string
    {
        return match ($row['key']) {
            OpenBySystemBreakdown::UNASSIGNED_KEY, OpenBySystemBreakdown::OTHERS_KEY => null,
            default => SecurityEventResource::filteredIndexUrl(['system_scope' => [$row['key']], 'state' => [EventState::Open->value]]),
        };
    }
}
