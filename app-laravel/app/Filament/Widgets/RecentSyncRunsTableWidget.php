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

                        return match ($duration) {
                            null => 'n/a',
                            default => $duration . 's',
                        };
                    }),
                TextColumn::make('counts_json')
                    ->label('Counts')
                    ->formatStateUsing(function (mixed $state): string {
                        if (! is_array($state)) {
                            return 'n/a';
                        }

                        $created = (int) ($state['events_created'] ?? 0);
                        $updated = (int) ($state['events_updated'] ?? 0);

                        return "created {$created} / updated {$updated}";
                    }),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginated(false);
    }
}
