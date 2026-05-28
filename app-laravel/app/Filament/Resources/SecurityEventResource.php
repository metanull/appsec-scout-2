<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SecurityEventResource\Pages\ListSecurityEvents;
use App\Filament\Resources\SecurityEventResource\Pages\ViewSecurityEvent;
use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SoftwareSystemLink;
use App\Models\User;
use App\Trackers\CreateWorkItemJob;
use App\Trackers\WorkItemFormOptions;
use App\Trackers\WorkItemService;
use App\Triage\SeverityChanger;
use App\Triage\StateChanger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class SecurityEventResource extends Resource
{
    protected static ?string $model = SecurityEvent::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static string|\UnitEnum|null $navigationGroup = 'Reader';

    protected static ?string $navigationLabel = 'Alerts';

    public static function canViewAny(): bool
    {
        return self::currentUserCan('alerts.view');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('workItemLinks')
                ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low', 'informational') DESC")
                ->orderByDesc('last_seen_at'))
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (EventSeverity|string $state) => match ($state instanceof EventSeverity ? $state->value : $state) {
                        EventSeverity::Critical->value => 'danger',
                        EventSeverity::High->value => 'warning',
                        EventSeverity::Medium->value => 'info',
                        EventSeverity::Low->value => 'gray',
                        default => 'secondary',
                    }),
                TextColumn::make('state')
                    ->badge()
                    ->color(fn (EventState|string $state) => match ($state instanceof EventState ? $state->value : $state) {
                        EventState::Resolved->value => 'success',
                        EventState::Dismissed->value => 'gray',
                        EventState::InProgress->value => 'info',
                        EventState::Acknowledged->value => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('is_dirty')
                    ->label('Sync')
                    ->state(fn (SecurityEvent $record): ?string => $record->is_dirty ? 'Pending' : null)
                    ->badge()
                    ->color('warning')
                    ->placeholder('-'),
                TextColumn::make('source_id')->label('Source')->badge(),
                TextColumn::make('type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (EventType|string $state): string => str($state instanceof EventType ? $state->value : $state)->replace('_', ' ')->title()->toString())
                    ->toggleable(),
                TextColumn::make('title')
                    ->searchable()
                    ->wrap()
                    ->grow(),
                TextColumn::make('work_item_state')
                    ->label('Tracker')
                    ->state(function (SecurityEvent $record): ?string {
                        $link = $record->workItemLinks->first();

                        if (! $link) {
                            return null;
                        }

                        return $link->work_item_state ?? $link->work_item_id;
                    })
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->dateTime('d M H:i')
                    ->sortable(),
                TextColumn::make('first_seen_at')
                    ->label('First seen')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(),
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
                SelectFilter::make('system_scope')
                    ->label('System')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::systemScopeOptions())
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applySystemScopes($query, self::stringArray($data['values'] ?? []))),
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
                TernaryFilter::make('is_dirty')
                    ->label('Pending sync')
                    ->queries(
                        true: fn (Builder $query) => $query->whereRaw('is_dirty = 1'),
                        false: fn (Builder $query) => $query->whereRaw('is_dirty = 0'),
                        blank: fn (Builder $query) => $query,
                    ),
                SelectFilter::make('tags')
                    ->multiple()
                    ->options(fn () => self::availableTags())
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applyTags($query, self::stringArray($data['values'] ?? []))),
                Filter::make('work_item')
                    ->form([])
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applyWorkItem(
                        $query,
                        is_string($data['tracker_id'] ?? null) ? $data['tracker_id'] : null,
                        is_string($data['work_item_id'] ?? null) ? $data['work_item_id'] : null,
                    ))
                    ->indicateUsing(function (array $data): ?string {
                        $tracker = is_string($data['tracker_id'] ?? null) ? $data['tracker_id'] : '';
                        $itemId = is_string($data['work_item_id'] ?? null) ? $data['work_item_id'] : '';

                        return ($tracker !== '' && $itemId !== '') ? "Work item: {$tracker}/{$itemId}" : null;
                    }),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('changeState')
                        ->label('Change state')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (): bool => self::currentUserCan('alerts.edit'))
                        ->form(self::stateChangeForm())
                        ->action(function (SecurityEvent $record, array $data): void {
                            /** @var User|null $user */
                            $user = Auth::user();

                            if ($user === null) {
                                abort(403);
                            }

                            app(StateChanger::class)->change(
                                $record,
                                $user,
                                EventState::from((string) $data['new_state']),
                                (string) $data['comment'],
                            );
                        }),
                    Action::make('changeSeverity')
                        ->label('Change severity')
                        ->icon('heroicon-o-adjustments-horizontal')
                        ->visible(fn (): bool => self::currentUserCan('alerts.edit'))
                        ->form(self::severityChangeForm())
                        ->action(function (SecurityEvent $record, array $data): void {
                            /** @var User|null $user */
                            $user = Auth::user();

                            if ($user === null) {
                                abort(403);
                            }

                            app(SeverityChanger::class)->change(
                                $record,
                                $user,
                                EventSeverity::from((string) $data['new_severity']),
                                (string) $data['comment'],
                            );
                        }),
                    Action::make('createWorkItem')
                        ->label('Create work item')
                        ->icon('heroicon-o-ticket')
                        ->visible(fn (): bool => self::currentUserCan('work-items.create'))
                        ->form(fn (SecurityEvent $record): array => app(WorkItemFormOptions::class)->createSchema([$record]))
                        ->action(function (SecurityEvent $record, array $data): void {
                            /** @var User|null $user */
                            $user = Auth::user();

                            if ($user === null) {
                                abort(403);
                            }

                            CreateWorkItemJob::dispatch(
                                eventIds: [$record->id],
                                userId: $user->id,
                                trackerId: (string) $data['tracker'],
                                projectKey: (string) $data['project'],
                                itemType: (string) $data['item_type'],
                                labels: self::stringArray($data['labels'] ?? []),
                                priority: self::nullableString($data['priority'] ?? null),
                                assigneeId: self::nullableString($data['assignee_id'] ?? null),
                                parentId: self::nullableString($data['parent_id'] ?? null),
                            );

                            Notification::make()->title('Work item creation queued')->success()->send();
                        }),
                    Action::make('linkExistingWorkItem')
                        ->label('Link existing work item')
                        ->icon('heroicon-o-link')
                        ->visible(fn (): bool => self::currentUserCan('work-items.link'))
                        ->form(fn (): array => app(WorkItemFormOptions::class)->linkSchema())
                        ->action(function (SecurityEvent $record, array $data): void {
                            /** @var User|null $user */
                            $user = Auth::user();

                            if ($user === null) {
                                abort(403);
                            }

                            app(WorkItemService::class)->linkExisting(
                                eventIds: [$record->id],
                                userId: $user->id,
                                trackerId: (string) $data['tracker'],
                                workItemId: (string) $data['selected_work_item'],
                            );

                            Notification::make()->title('Work item linked')->success()->send();
                        }),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Actions'),
            ])
            ->bulkActions([
                BulkAction::make('changeStateBulk')
                    ->label('Change state (bulk)')
                    ->icon('heroicon-o-pencil-square')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => self::currentUserCan('alerts.bulk-edit'))
                    ->form(self::stateChangeForm())
                    ->action(function (Collection $records, array $data): void {
                        /** @var Collection<int, SecurityEvent> $records */
                        /** @var User|null $user */
                        $user = Auth::user();

                        if ($user === null) {
                            abort(403);
                        }

                        app(StateChanger::class)->changeMany(
                            $records,
                            $user,
                            EventState::from((string) $data['new_state']),
                            (string) $data['comment'],
                        );
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('createGroupedWorkItem')
                    ->label('Create grouped work item')
                    ->icon('heroicon-o-ticket')
                    ->visible(fn (): bool => self::currentUserCan('work-items.create'))
                    ->form(fn (): array => app(WorkItemFormOptions::class)->createSchema())
                    ->action(function (Collection $records, array $data): void {
                        /** @var Collection<int, SecurityEvent> $records */
                        /** @var User|null $user */
                        $user = Auth::user();

                        if ($user === null) {
                            abort(403);
                        }

                        CreateWorkItemJob::dispatch(
                            eventIds: array_values($records->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()),
                            userId: $user->id,
                            trackerId: (string) $data['tracker'],
                            projectKey: (string) $data['project'],
                            itemType: (string) $data['item_type'],
                            labels: self::stringArray($data['labels'] ?? []),
                            priority: self::nullableString($data['priority'] ?? null),
                            assigneeId: self::nullableString($data['assignee_id'] ?? null),
                            parentId: self::nullableString($data['parent_id'] ?? null),
                        );

                        Notification::make()->title('Grouped work item creation queued')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('linkExistingWorkItemBulk')
                    ->label('Link existing')
                    ->icon('heroicon-o-link')
                    ->visible(fn (): bool => self::currentUserCan('work-items.link'))
                    ->form(fn (): array => app(WorkItemFormOptions::class)->linkSchema())
                    ->action(function (Collection $records, array $data): void {
                        /** @var Collection<int, SecurityEvent> $records */
                        /** @var User|null $user */
                        $user = Auth::user();

                        if ($user === null) {
                            abort(403);
                        }

                        app(WorkItemService::class)->linkExisting(
                            eventIds: array_values($records->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()),
                            userId: $user->id,
                            trackerId: (string) $data['tracker'],
                            workItemId: (string) $data['selected_work_item'],
                        );

                        Notification::make()->title('Existing work item linked')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
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

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /** @return array<string, string> */
    private static function systemScopeOptions(): array
    {
        $physical = SoftwareSystem::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (SoftwareSystem $system): array => ['physical:' . $system->id => '[System] ' . $system->name])
            ->all();

        $virtual = SoftwareSystemLink::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (SoftwareSystemLink $link): array => ['virtual:' . $link->id => '[Virtual] ' . $link->name])
            ->all();

        return array_merge($physical, $virtual);
    }

    /**
     * Build a URL to the alert list with pre-applied filter state.
     *
     * The Filament table filter query parameter format (Livewire 3 URL binding) is:
     *   tableFilters[{filter_name}][values][0]=value  (for SelectFilter with multiple())
     *   tableFilters[{filter_name}][value]=value       (for single-value filters)
     *
     * @param  array<string, list<string>>  $multiSelectFilters  filter name => list of values
     */
    public static function filteredIndexUrl(array $multiSelectFilters = []): string
    {
        $params = [];

        foreach ($multiSelectFilters as $filterName => $values) {
            foreach ($values as $idx => $value) {
                $params['tableFilters'][$filterName]['values'][$idx] = $value;
            }
        }

        $base = static::getUrl('index');

        return $params !== [] ? $base . '?' . http_build_query($params) : $base;
    }

    /**
     * Build a pre-filtered alert list URL showing only alerts linked to the given work item.
     */
    public static function workItemFilterUrl(string $trackerId, string $workItemId): string
    {
        $params = [
            'tableFilters' => [
                'work_item' => [
                    'tracker_id' => $trackerId,
                    'work_item_id' => $workItemId,
                ],
            ],
        ];

        return static::getUrl('index') . '?' . http_build_query($params);
    }

    /** @return list<string> */
    public static function stringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    private static function currentUserCan(string $ability): bool
    {
        $user = Auth::user();

        return $user instanceof User ? $user->can($ability) : false;
    }

    /** @return array<int, Select|Textarea> */
    public static function stateChangeForm(): array
    {
        return [
            Select::make('new_state')
                ->label('New state')
                ->required()
                ->options(self::eventStateOptions()),
            Textarea::make('comment')
                ->label('Comment')
                ->required()
                ->minLength(10)
                ->rows(4),
        ];
    }

    /** @return array<string, string> */
    public static function eventStateOptions(): array
    {
        return collect(EventState::cases())
            ->mapWithKeys(fn (EventState $state): array => [$state->value => str($state->value)->replace('_', ' ')->title()->toString()])
            ->all();
    }

    /** @return array<int, Select|Textarea> */
    public static function severityChangeForm(): array
    {
        return [
            Select::make('new_severity')
                ->label('New severity')
                ->required()
                ->options(self::eventSeverityOptions()),
            Textarea::make('comment')
                ->label('Comment')
                ->required()
                ->minLength(10)
                ->rows(4),
        ];
    }

    /** @return array<string, string> */
    public static function eventSeverityOptions(): array
    {
        return collect(EventSeverity::cases())
            ->mapWithKeys(fn (EventSeverity $severity): array => [$severity->value => ucfirst($severity->value)])
            ->all();
    }
}
