<?php

namespace App\Filament\Resources;

use App\Audit\AuditLog;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogResource\Pages\ViewAuditLog;
use App\Filament\Resources\AuditLogResource\Support\AuditSubjectResolver;
use App\Filament\Support\ActorKindBadgeColor;
use App\Filament\Support\DateRangeFilters;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Phiki\Grammar\Grammar;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Audit Log';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.audit') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /** @return Builder<AuditLog> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<AuditLog> $query */
        $query = parent::getEloquentQuery();

        return $query->with(['user']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Event')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('created_at')
                            ->label('Timestamp')
                            ->dateTime('d M Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('actor_kind')
                            ->label('Actor kind')
                            ->badge()
                            ->color(fn (string $state): string => ActorKindBadgeColor::for($state)),
                        TextEntry::make('ip')
                            ->label('IP address')
                            ->placeholder('-'),
                        TextEntry::make('action')
                            ->label('Action')
                            ->grow()
                            ->columnSpan(2),
                        TextEntry::make('user.name')
                            ->label('User')
                            ->url(fn (AuditLog $record): ?string => self::userUrl($record))
                            ->placeholder('-'),
                    ]),
                    KeyValueEntry::make('_payload_summary')
                        ->label('Details')
                        ->state(fn (AuditLog $record): array => self::promotedPayload($record))
                        ->columnSpanFull(),
                ]),

            Section::make('Subject')
                ->schema(self::subjectEntries()),

            Section::make('Payload')
                ->collapsible()
                ->schema([
                    CodeEntry::make('_payload')
                        ->label('')
                        ->state(fn (AuditLog $record): array => is_array($record->payload_json) ? $record->payload_json : [])
                        ->grammar(Grammar::Json)
                        ->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        ->copyable()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),
                TextColumn::make('actor_kind')
                    ->badge()
                    ->color(fn (string $state): string => ActorKindBadgeColor::for($state))
                    ->placeholder('-'),
                TextColumn::make('action')
                    ->searchable()
                    ->wrap()
                    ->grow(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('-'),
                self::subjectColumn(),
                TextColumn::make('subject_type')
                    ->label('Subject type')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->badge()
                    ->color('gray')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('subject_id')
                    ->label('Subject ID')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ip')
                    ->label('IP')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payload_json')
                    ->label('Payload')
                    ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '')
                    ->wrap()
                    ->limit(120)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('actor_kind')
                    ->options(['user' => 'User', 'job' => 'Job', 'cli' => 'CLI', 'system' => 'System']),
                ...DateRangeFilters::for('created_at'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->recordUrl(fn (AuditLog $record): string => AuditLogResource::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }

    private static function userUrl(AuditLog $record): ?string
    {
        return $record->user !== null
            ? UserResource::getUrl('edit', ['record' => $record->user_id])
            : null;
    }

    /** @return array<string, string> */
    private static function promotedPayload(AuditLog $record): array
    {
        if (! is_array($record->payload_json)) {
            return [];
        }

        $promoted = [];

        foreach ($record->payload_json as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $promoted[(string) $key] = $value === null ? '-' : (string) $value;
            }
        }

        return $promoted;
    }

    /**
     * A single "Subject" table column, batch-resolving every row's subject
     * in one query per type the first time any row on this page needs it —
     * $subjectMap is captured by reference and local to this one table()
     * call (one per request), so nothing here is cached across requests.
     */
    private static function subjectColumn(): TextColumn
    {
        $subjectMap = null;

        $resolve = function (AuditLog $record, HasTable $livewire) use (&$subjectMap): ?array {
            if ($record->subject_type === null || $record->subject_id === null) {
                return null;
            }

            if ($subjectMap === null) {
                $records = $livewire->getTableRecords();

                /** @var array<int, AuditLog> $items */
                $items = $records instanceof Collection ? $records->all() : $records->items();

                $subjectMap = AuditSubjectResolver::resolveForRecords($items);
            }

            return $subjectMap["{$record->subject_type}:{$record->subject_id}"] ?? null;
        };

        return TextColumn::make('subject')
            ->label('Subject')
            ->state(fn (AuditLog $record, HasTable $livewire): ?string => $resolve($record, $livewire)['name'] ?? null)
            ->url(fn (AuditLog $record, HasTable $livewire): ?string => $resolve($record, $livewire)['url'] ?? null)
            ->placeholder('-')
            ->toggleable();
    }

    /**
     * The Subject section's entries — $subjectInfo is captured by reference
     * and local to this one infolist() call (one per request/record), so
     * Name/Kind/System share a single resolveOne() lookup instead of three.
     *
     * @return array<int, Grid>
     */
    private static function subjectEntries(): array
    {
        $subjectInfo = null;
        $resolved = false;

        $resolve = function (AuditLog $record) use (&$subjectInfo, &$resolved): ?array {
            if (! $resolved) {
                $subjectInfo = AuditSubjectResolver::resolveOne($record);
                $resolved = true;
            }

            return $subjectInfo;
        };

        return [
            Grid::make(3)->schema([
                TextEntry::make('_subject_name')
                    ->label('Name')
                    ->state(fn (AuditLog $record): ?string => $resolve($record)['name'] ?? null)
                    ->url(fn (AuditLog $record): ?string => $resolve($record)['url'] ?? null)
                    ->placeholder('-'),
                TextEntry::make('_subject_kind')
                    ->label('Kind')
                    ->state(fn (AuditLog $record): ?string => $resolve($record)['kind'] ?? null)
                    ->placeholder('-'),
                TextEntry::make('_subject_system')
                    ->label('System')
                    ->state(fn (AuditLog $record): ?string => $resolve($record)['system'] ?? null)
                    ->placeholder('-'),
                TextEntry::make('subject_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '-')
                    ->placeholder('-'),
                TextEntry::make('subject_id')
                    ->label('ID')
                    ->placeholder('-'),
            ]),
        ];
    }
}
