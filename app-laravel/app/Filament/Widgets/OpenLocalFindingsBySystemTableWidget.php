<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LocalFindingResource;
use App\Filament\Widgets\Support\BreakdownTableWidget;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;
use App\Filament\Widgets\Support\OpenBySystemBreakdown;
use App\Models\Enums\EventState;

class OpenLocalFindingsBySystemTableWidget extends BreakdownTableWidget
{
    protected static ?string $heading = 'Open Local Findings by Software System';

    protected function labelColumnHeading(): string
    {
        return 'System';
    }

    protected function emptyStateNote(): string
    {
        return 'No open local findings.';
    }

    protected function rows(): array
    {
        return LocalFindingBreakdownData::openBySystemBreakdown();
    }

    protected function rowUrl(array $row): ?string
    {
        return match ($row['key']) {
            OpenBySystemBreakdown::UNASSIGNED_KEY, OpenBySystemBreakdown::OTHERS_KEY => null,
            default => LocalFindingResource::filteredIndexUrl(['system_scope' => [$row['key']], 'status' => [EventState::Open->value]]),
        };
    }
}
