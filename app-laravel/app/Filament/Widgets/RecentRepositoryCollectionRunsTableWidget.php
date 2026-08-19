<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\RepositoryCollectionRunResource;
use App\Filament\Support\RunCounts;
use App\Filament\Support\RunStatusBadgeColor;
use App\Models\RepositoryCollectionRun;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

class RecentRepositoryCollectionRunsTableWidget extends TableWidget
{
    protected static ?string $heading = 'Recent Repository Collection Runs';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return Auth::user()?->can('admin.queue') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => RepositoryCollectionRun::query()->latest('started_at')->limit(5)->get())
            ->columns([
                TextColumn::make('source_control_id')->label('Source Control')->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => RunStatusBadgeColor::for($state)),
                TextColumn::make('started_at')->dateTime(),
                TextColumn::make('duration')->label('Duration')
                    ->state(function (RepositoryCollectionRun $record): string {
                        $duration = RunCounts::durationSeconds($record);

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
                    ->state(fn (RepositoryCollectionRun $record): string => RunCounts::format($record->counts_json)),
            ])
            ->headerActions([
                Action::make('viewAll')
                    ->label('View all')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (): string => RepositoryCollectionRunResource::getUrl('index')),
            ])
            ->recordUrl(fn (RepositoryCollectionRun $record): string => RepositoryCollectionRunResource::getUrl('view', ['record' => $record]))
            ->defaultSort('started_at', 'desc')
            ->paginated(false)
            ->poll('30s');
    }
}
