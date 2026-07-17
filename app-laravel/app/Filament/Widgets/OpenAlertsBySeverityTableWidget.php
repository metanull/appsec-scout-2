<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownTableWidget;
use App\Models\Enums\EventState;

class OpenAlertsBySeverityTableWidget extends BreakdownTableWidget
{
    protected static ?int $sort = -102;

    protected static ?string $heading = 'Open Alerts by Severity';

    protected function labelColumnHeading(): string
    {
        return 'Severity';
    }

    protected function emptyStateNote(): string
    {
        return 'No open alerts.';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::openBySeverityBreakdown();
    }

    protected function rowUrl(array $row): ?string
    {
        return SecurityEventResource::filteredIndexUrl([
            'severity' => [$row['key']],
            'state' => [EventState::Open->value],
        ]);
    }
}
