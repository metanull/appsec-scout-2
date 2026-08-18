<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ErrorLogResource\Pages\ListErrorLogs;
use App\Filament\Resources\ErrorLogResource\Pages\ViewErrorLog;
use App\Filament\Support\DateRangeFilters;
use App\Models\ErrorLog;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Phiki\Grammar\Grammar;

class ErrorLogResource extends Resource
{
    protected static ?string $model = ErrorLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 24;

    protected static ?string $navigationLabel = 'Errors';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.errors') ?? false;
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
            Section::make('Error')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('occurred_at')
                            ->label('Occurred')
                            ->dateTime('d M Y H:i:s'),
                        TextEntry::make('level')
                            ->badge()
                            ->color(fn (string $state) => match (strtolower($state)) {
                                'error', 'critical', 'alert', 'emergency' => 'danger',
                                'warning' => 'warning',
                                default => 'secondary',
                            }),
                        TextEntry::make('channel'),
                        TextEntry::make('message')
                            ->wrap()
                            ->columnSpan(3),
                    ]),
                ]),

            Section::make('Context')
                ->collapsible()
                ->schema([
                    CodeEntry::make('_context')
                        ->label('')
                        ->state(fn (ErrorLog $record): array => is_array($record->context_json) ? $record->context_json : [])
                        ->grammar(Grammar::Json)
                        ->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        ->copyable()
                        ->columnSpanFull(),
                ]),

            Section::make('Trace')
                ->collapsible()
                ->schema([
                    TextEntry::make('trace')
                        ->label('')
                        ->placeholder('-')
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
                TextColumn::make('occurred_at')->dateTime()->sortable(),
                TextColumn::make('level')->badge()->color(fn (string $state) => match (strtolower($state)) {
                    'error', 'critical', 'alert', 'emergency' => 'danger',
                    'warning' => 'warning',
                    default => 'secondary',
                }),
                TextColumn::make('channel'),
                TextColumn::make('message')->searchable()->wrap(),
                TextColumn::make('context_json')
                    ->label('Context')
                    ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '')
                    ->wrap()
                    ->limit(120)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('trace')
                    ->limit(500)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('level')
                    ->options(['ERROR' => 'Error', 'CRITICAL' => 'Critical', 'ALERT' => 'Alert', 'EMERGENCY' => 'Emergency']),
                Filter::make('run')
                    ->form([
                        TextInput::make('value')
                            ->label('Collection run ID')
                            ->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        // Larastan's where() stub types $column as model-property<TModel> only,
                        // with no case for Laravel's own column->jsonKey path syntax.
                        // @phpstan-ignore argument.type
                        ? $query->where('context_json->run', (int) $data['value'])
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null) ? "Run: {$data['value']}" : null),
                ...DateRangeFilters::for('occurred_at'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginated([25, 50, 100])
            ->recordUrl(fn (ErrorLog $record): string => ErrorLogResource::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListErrorLogs::route('/'),
            'view' => ViewErrorLog::route('/{record}'),
        ];
    }
}
