<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Support\DashboardData;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

class RecentSyncRunsTableWidget extends TableWidget
{
    protected static ?string $heading = 'Recent Sync Runs';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return Auth::user()?->can('alerts.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => DashboardData::recentSyncRuns())
            ->columns([
                TextColumn::make('source_id')->label('Source')->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'success' ? 'success' : ($state === 'failure' ? 'danger' : 'warning')),
                TextColumn::make('started_at')->dateTime(),
                TextColumn::make('duration')->label('Duration')
                    ->state(function ($record): string {
                        $duration = DashboardData::durationSeconds($record);

                        if ($duration === null) {
                            return 'n/a';
                        }

                        if ($duration >= 60) {
                            return round($duration / 60, 1) . 'm';
                        }

                        return $duration . 's';
                    }),
                TextColumn::make('counts_json')
                    ->label('Counts')
                    ->formatStateUsing(fn (mixed $state): string => DashboardData::formatCounts($state)),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginated(false);
    }
}
