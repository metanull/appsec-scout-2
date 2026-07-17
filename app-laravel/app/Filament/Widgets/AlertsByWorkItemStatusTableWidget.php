<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\BreakdownTableWidget;
use App\Filament\Widgets\Support\WorkItemStatusBreakdown;

class AlertsByWorkItemStatusTableWidget extends BreakdownTableWidget
{
    protected static ?string $heading = 'Alerts by Work Item status';

    protected function labelColumnHeading(): string
    {
        return 'Work item status';
    }

    protected function emptyStateNote(): string
    {
        return 'No alerts recorded.';
    }

    protected function tableDescription(): ?string
    {
        return 'A record is counted once per distinct work-item status; rows may sum to more than the total.';
    }

    protected function rows(): array
    {
        return AlertBreakdownData::workItemStatusBreakdown();
    }

    protected function rowUrl(array $row): ?string
    {
        return match ($row['key']) {
            WorkItemStatusBreakdown::UNKNOWN_KEY => SecurityEventResource::filteredIndexUrl(['work_item_state' => [WorkItemStatusBreakdown::UNKNOWN_KEY]]),
            WorkItemStatusBreakdown::NO_WORK_ITEM_KEY => SecurityEventResource::filteredIndexUrl(['has_work_item' => ['0']]),
            default => SecurityEventResource::filteredIndexUrl(['work_item_state' => [$row['key']]]),
        };
    }
}
