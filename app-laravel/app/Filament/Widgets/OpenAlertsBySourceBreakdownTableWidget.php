<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownTableWidget;
use App\Models\Enums\EventState;

class OpenAlertsBySourceBreakdownTableWidget extends BreakdownTableWidget
{
    protected static ?int $sort = -108;

    protected static ?string $heading = 'Open Alerts by Source';

    protected function labelColumnHeading(): string
    {
        return 'Source';
    }

    protected function emptyStateNote(): string
    {
        return 'No open alerts.';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::openBySourceBreakdown();
    }

    protected function rowUrl(array $row): ?string
    {
        return SecurityEventResource::filteredIndexUrl([
            'source_id' => [$row['key']],
            'state' => [EventState::Open->value],
        ]);
    }
}
