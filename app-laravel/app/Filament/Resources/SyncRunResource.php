<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SyncRunResource\Pages\ListSyncRuns;
use App\Filament\Resources\SyncRunResource\Pages\ViewSyncRun;
use App\Filament\Support\DateRangeFilters;
use App\Filament\Widgets\Support\DashboardData;
use App\Models\SyncRun;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static bool $shouldRegisterNavigation = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.queue') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Sync Run')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('source_id')
                            ->label('Source')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'success' => 'success',
                                'failure' => 'danger',
                                default => 'warning',
                            }),
                        TextEntry::make('started_at')
                            ->label('Started')
                            ->dateTime('d M Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('finished_at')
                            ->label('Finished')
                            ->dateTime('d M Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('error_message')
                            ->label('Error')
                            ->wrap()
                            ->placeholder('-')
                            ->columnSpan(2),
                    ]),
                ]),

            Section::make('Counts')
                ->collapsible()
                ->schema([
                    TextEntry::make('_counts')
                        ->label('')
                        ->state(fn (SyncRun $record): string => json_encode(
                            $record->counts_json ?? [],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                        ) ?: '{}')
                        ->fontFamily('mono')
                        ->copyable()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source_id')
                    ->label('Source')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'failure' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->since()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('counts_json')
                    ->label('Counts')
                    ->state(fn (SyncRun $record): string => DashboardData::formatCounts($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->wrap()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source_id')
                    ->options(fn (): array => SyncRun::query()->distinct()->pluck('source_id', 'source_id')->all()),
                SelectFilter::make('status')
                    ->options(['success' => 'Success', 'failure' => 'Failure', 'running' => 'Running']),
                ...DateRangeFilters::for('started_at', 'Started from', 'Started until'),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginated([25, 50, 100])
            ->recordUrl(fn (SyncRun $record): string => SyncRunResource::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSyncRuns::route('/'),
            'view' => ViewSyncRun::route('/{record}'),
        ];
    }
}
