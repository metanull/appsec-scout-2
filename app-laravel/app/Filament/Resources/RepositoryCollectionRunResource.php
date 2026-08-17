<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RepositoryCollectionRunResource\Pages\ListRepositoryCollectionRuns;
use App\Filament\Resources\RepositoryCollectionRunResource\Pages\ViewRepositoryCollectionRun;
use App\Filament\Support\DateRangeFilters;
use App\Models\RepositoryCollectionRun;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Phiki\Grammar\Grammar;

class RepositoryCollectionRunResource extends Resource
{
    protected static ?string $model = RepositoryCollectionRun::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-code-bracket-square';

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
            Section::make('Repository Collection Run')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('source_control_id')
                            ->label('Source Control')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'success' => 'success',
                                'failure' => 'danger',
                                'partial' => 'warning',
                                default => 'warning',
                            }),
                        TextEntry::make('batch_id')
                            ->label('Batch ID')
                            ->placeholder('-'),
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
                    CodeEntry::make('_counts')
                        ->label('')
                        ->state(fn (RepositoryCollectionRun $record): array => (array) $record->counts_json)
                        ->grammar(Grammar::Json)
                        ->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        ->copyable()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source_control_id')
                    ->label('Source Control')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'failure' => 'danger',
                        'partial' => 'warning',
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
                    ->state(fn (RepositoryCollectionRun $record): string => static::formatCounts($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->wrap()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source_control_id')
                    ->options(fn (): array => RepositoryCollectionRun::query()->distinct()->pluck('source_control_id', 'source_control_id')->all()),
                SelectFilter::make('status')
                    ->options(['success' => 'Success', 'partial' => 'Partial', 'failure' => 'Failure', 'running' => 'Running']),
                ...DateRangeFilters::for('started_at', 'Started from', 'Started until'),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginated([25, 50, 100])
            ->recordUrl(fn (RepositoryCollectionRun $record): string => RepositoryCollectionRunResource::getUrl('view', ['record' => $record]));
    }

    public static function formatCounts(RepositoryCollectionRun $run): string
    {
        $counts = $run->getAttribute('counts_json');
        $counts = is_array($counts) ? $counts : [];

        $considered = (int) ($counts['repositories_considered'] ?? 0);
        $completed = (int) ($counts['repositories_completed'] ?? 0);
        $failed = (int) ($counts['repositories_failed'] ?? 0);

        return "{$completed} / {$considered} · {$failed} failed";
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepositoryCollectionRuns::route('/'),
            'view' => ViewRepositoryCollectionRun::route('/{record}'),
        ];
    }
}
