<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SyncRunResource;
use App\Filament\Widgets\Support\DashboardData;
use App\Models\SyncRun;
use Filament\Actions\Action;
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
                    ->state(fn (SyncRun $record): string => DashboardData::formatCounts($record)),
            ])
            ->headerActions([
                Action::make('viewAll')
                    ->label('View all')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (): string => SyncRunResource::getUrl('index')),
            ])
            ->recordUrl(fn (SyncRun $record): string => SyncRunResource::getUrl('view', ['record' => $record]))
            ->defaultSort('started_at', 'desc')
            ->paginated(false);
    }
}
