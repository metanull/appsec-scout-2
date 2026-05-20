<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SecurityEventResource\Pages\ListSecurityEvents;
use App\Filament\Resources\SecurityEventResource\Pages\ViewSecurityEvent;
use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityEvent;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class SecurityEventResource extends Resource
{
    protected static ?string $model = SecurityEvent::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static string|\UnitEnum|null $navigationGroup = 'Reader';

    protected static ?string $navigationLabel = 'Alerts';

    public static function canViewAny(): bool
    {
        return Auth::user()?->can('alerts.view') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low', 'informational') DESC")
                ->orderByDesc('last_seen_at'))
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        EventSeverity::Critical->value => 'danger',
                        EventSeverity::High->value => 'warning',
                        EventSeverity::Medium->value => 'info',
                        EventSeverity::Low->value => 'gray',
                        default => 'secondary',
                    }),
                TextColumn::make('state')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        EventState::Resolved->value => 'success',
                        EventState::Dismissed->value => 'gray',
                        EventState::InProgress->value => 'info',
                        EventState::Acknowledged->value => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('source_id')->label('Source')->badge(),
                TextColumn::make('metadata.work_item_id')
                    ->label('Tracker')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('title')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->since()
                    ->sortable(),
                TextColumn::make('first_seen_at')
                    ->label('First seen')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('severity')
                    ->multiple()
                    ->options(collect(EventSeverity::cases())->mapWithKeys(fn (EventSeverity $severity) => [$severity->value => ucfirst($severity->value)])->all())
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applySeverities($query, self::stringArray($data['values'] ?? []))),
                SelectFilter::make('state')
                    ->multiple()
                    ->options(collect(EventState::cases())->mapWithKeys(fn (EventState $state) => [$state->value => str($state->value)->replace('_', ' ')->title()->toString()])->all())
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applyStates($query, self::stringArray($data['values'] ?? []))),
                SelectFilter::make('source_id')
                    ->label('Source')
                    ->multiple()
                    ->options(fn () => SecurityEvent::query()->distinct()->pluck('source_id', 'source_id')->all())
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applySources($query, self::stringArray($data['values'] ?? []))),
                SelectFilter::make('software_system_id')
                    ->label('Software system')
                    ->relationship('softwareSystem', 'name')
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applySystem($query, self::nullableInt($data['value'] ?? null))),
                SelectFilter::make('container_id')
                    ->label('Container')
                    ->relationship('container', 'name')
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applyContainer($query, self::nullableInt($data['value'] ?? null))),
                SelectFilter::make('type')
                    ->multiple()
                    ->options(collect(EventType::cases())->mapWithKeys(fn (EventType $type) => [$type->value => str($type->value)->replace('_', ' ')->title()->toString()])->all())
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applyTypes($query, self::stringArray($data['values'] ?? []))),
                TernaryFilter::make('has_work_item')
                    ->label('Has work item')
                    ->queries(
                        true: fn (Builder $query) => SecurityEventTableQuery::applyHasWorkItem($query, true),
                        false: fn (Builder $query) => SecurityEventTableQuery::applyHasWorkItem($query, false),
                        blank: fn (Builder $query) => $query,
                    ),
                SelectFilter::make('tags')
                    ->multiple()
                    ->options(fn () => self::availableTags())
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applyTags($query, self::stringArray($data['values'] ?? []))),
            ])
            ->recordUrl(fn (SecurityEvent $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultPaginationPageOption(25)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSecurityEvents::route('/'),
            'view' => ViewSecurityEvent::route('/{record}'),
        ];
    }

    /** @return array<string, string> */
    private static function availableTags(): array
    {
        $tags = SecurityEvent::query()->pluck('metadata')
            ->flatMap(function (mixed $metadata): array {
                if (! is_array($metadata)) {
                    return [];
                }

                $rawTags = $metadata['tags'] ?? [];

                return is_array($rawTags) ? $rawTags : [];
            })
            ->filter(fn (mixed $tag): bool => is_string($tag) && $tag !== '')
            ->unique()
            ->sort()
            ->values();

        return $tags->mapWithKeys(fn (string $tag): array => [$tag => $tag])->all();
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /** @return list<string> */
    private static function stringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== ''));
    }
}
