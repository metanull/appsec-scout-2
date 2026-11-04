<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LocalFindingResource;
use App\Filament\Widgets\Support\BreakdownTableWidget;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;
use App\Filament\Widgets\Support\WorkItemStatusBreakdown;
use App\Models\Enums\EventState;

class LocalFindingsByWorkItemStatusTableWidget extends BreakdownTableWidget
{
    protected static ?string $heading = 'Open Local Findings by Work Item status';

    protected function labelColumnHeading(): string
    {
        return 'Work item status';
    }

    protected function emptyStateNote(): string
    {
        return 'No open local findings.';
    }

    protected function tableDescription(): ?string
    {
        return 'A record is counted once per distinct work-item status; rows may sum to more than the total.';
    }

    protected function rows(): array
    {
        return LocalFindingBreakdownData::workItemStatusBreakdown();
    }

    protected function rowUrl(array $row): ?string
    {
        $open = ['status' => [EventState::Open->value]];

        return match ($row['key']) {
            WorkItemStatusBreakdown::UNKNOWN_KEY => LocalFindingResource::filteredIndexUrl(['work_item_state' => [WorkItemStatusBreakdown::UNKNOWN_KEY]] + $open),
            WorkItemStatusBreakdown::NO_WORK_ITEM_KEY => LocalFindingResource::filteredIndexUrl(['has_work_item' => ['0']] + $open),
            default => LocalFindingResource::filteredIndexUrl(['work_item_state' => [$row['key']]] + $open),
        };
    }
}
