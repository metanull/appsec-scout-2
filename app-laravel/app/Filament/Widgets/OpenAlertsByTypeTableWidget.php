<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownTableWidget;
use App\Models\Enums\EventState;

class OpenAlertsByTypeTableWidget extends BreakdownTableWidget
{
    protected static ?int $sort = -106;

    protected static ?string $heading = 'Open Alerts by Type';

    protected function labelColumnHeading(): string
    {
        return 'Type';
    }

    protected function emptyStateNote(): string
    {
        return 'No open alerts.';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::openByTypeBreakdown();
    }

    protected function rowUrl(array $row): ?string
    {
        return SecurityEventResource::filteredIndexUrl([
            'type' => [$row['key']],
            'state' => [EventState::Open->value],
        ]);
    }
}
