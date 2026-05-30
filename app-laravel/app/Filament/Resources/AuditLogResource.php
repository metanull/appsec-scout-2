<?php

namespace App\Filament\Resources;

use App\Audit\AuditLog;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogResource\Pages\ViewAuditLog;
use App\Models\SecurityEvent;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
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
use Illuminate\Support\Carbon;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 26;

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
                            ->color(fn (string $state): string => match ($state) {
                                'user' => 'info',
                                'job' => 'gray',
                                'cli' => 'warning',
                                'system' => 'secondary',
                                default => 'secondary',
                            }),
                        TextEntry::make('ip')
                            ->label('IP address')
                            ->placeholder('-'),
                        TextEntry::make('action')
                            ->label('Action')
                            ->grow()
                            ->columnSpan(2),
                        TextEntry::make('user_id')
                            ->label('User')
                            ->state(fn (AuditLog $record): string => self::resolveUserName($record))
                            ->url(fn (AuditLog $record): ?string => self::resolveUserUrl($record))
                            ->placeholder('—'),
                    ]),
                ]),

            Section::make('Subject')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('subject_type')
                            ->label('Type')
                            ->formatStateUsing(fn (?string $state): string => $state
                                ? class_basename($state)
                                : '—')
                            ->placeholder('-'),
                        TextEntry::make('subject_id')
                            ->label('ID')
                            ->url(fn (AuditLog $record): ?string => self::resolveSubjectUrl($record))
                            ->placeholder('-'),
                    ]),
                ]),

            Section::make('Payload')
                ->collapsible()
                ->schema([
                    TextEntry::make('_payload_redacted')
                        ->label('')
                        ->state(fn (AuditLog $record): string => json_encode(
                            self::redactPayload($record),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
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
                TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),
                TextColumn::make('actor_kind')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'user' => 'info',
                        'job' => 'gray',
                        'cli' => 'warning',
                        'system' => 'secondary',
                        default => 'secondary',
                    })
                    ->placeholder('-'),
                TextColumn::make('action')
                    ->searchable()
                    ->wrap()
                    ->grow(),
                TextColumn::make('user_id')
                    ->label('User')
                    ->formatStateUsing(fn (AuditLog $record): string => self::resolveUserName($record))
                    ->placeholder('-'),
                TextColumn::make('subject_type')
                    ->label('Subject')
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
                Filter::make('date_from')
                    ->form([DatePicker::make('date_from')])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date_from'],
                        fn (Builder $q, string $v) => $q->whereDate('created_at', '>=', Carbon::parse($v)),
                    )),
                Filter::make('date_until')
                    ->form([DatePicker::make('date_until')])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date_until'],
                        fn (Builder $q, string $v) => $q->whereDate('created_at', '<=', Carbon::parse($v)),
                    )),
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

    private static function resolveUserName(AuditLog $record): string
    {
        if ($record->user_id === null) {
            return '—';
        }

        $user = User::find($record->user_id);

        return $user instanceof User ? $user->name : "User #{$record->user_id}";
    }

    private static function resolveUserUrl(AuditLog $record): ?string
    {
        if ($record->user_id === null) {
            return null;
        }

        if (! User::where('id', $record->user_id)->exists()) {
            return null;
        }

        return UserResource::getUrl('edit', ['record' => $record->user_id]);
    }

    private static function resolveSubjectUrl(AuditLog $record): ?string
    {
        $subjectType = $record->subject_type;

        if ($subjectType === null || $record->subject_id === null) {
            return null;
        }

        $normalized = class_basename($subjectType);

        if ($normalized !== 'SecurityEvent') {
            return null;
        }

        if (! SecurityEvent::where('id', $record->subject_id)->exists()) {
            return null;
        }

        return SecurityEventResource::getUrl('view', ['record' => $record->subject_id]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function redactPayload(AuditLog $record): array
    {
        $payload = $record->payload_json;

        if (! is_array($payload)) {
            return [];
        }

        return self::redactArray($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function redactArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::redactArray($value);
            } elseif (is_scalar($value) && self::isSensitiveKey((string) $key)) {
                $result[$key] = '[redacted]';
            } elseif (is_string($value)) {
                $result[$key] = self::redactString($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private static function redactString(string $value): string
    {
        return (string) preg_replace(
            '/((token|secret|password|api[_-]?key|pat|authorization)[^=:\"]*[=:\"]\s*)([^\s\",}]+)/i',
            '$1[redacted]',
            $value,
        );
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return str_contains($normalized, 'token')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'api_key')
            || str_contains($normalized, 'apikey')
            || str_contains($normalized, 'pat')
            || str_contains($normalized, 'authorization');
    }
}
