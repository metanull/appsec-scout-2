<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Widgets\Support\DashboardData;
use App\Models\Enums\EventState;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

class OpenAlertsBySourceWidget extends TableWidget
{
    protected static ?string $heading = 'Open alerts by source';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        return Auth::user()?->can('alerts.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => collect(DashboardData::openAlertsBySourceAndWorkItemState()))
            ->columns([
                TextColumn::make('source_id')
                    ->label('Source')
                    ->url(fn (array $row): string => SecurityEventResource::filteredIndexUrl([
                        'source_id' => [$row['source_id']],
                        'state' => [EventState::Open->value],
                    ]))
                    ->badge()
                    ->color('info'),
                TextColumn::make('linked')
                    ->label('With work item')
                    ->url(fn (array $row): string => SecurityEventResource::filteredIndexUrl([
                        'source_id' => [$row['source_id']],
                        'state' => [EventState::Open->value],
                        'has_work_item' => ['1'],
                    ]))
                    ->badge()
                    ->color('success'),
                TextColumn::make('unlinked')
                    ->label('Without work item')
                    ->url(fn (array $row): string => SecurityEventResource::filteredIndexUrl([
                        'source_id' => [$row['source_id']],
                        'state' => [EventState::Open->value],
                        'has_work_item' => ['0'],
                    ]))
                    ->badge()
                    ->color('warning'),
            ])
            ->paginated(false)
            ->emptyStateDescription('No open alerts.');
    }
}
