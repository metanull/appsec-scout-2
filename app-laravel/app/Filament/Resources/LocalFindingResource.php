<?php

namespace App\Filament\Resources;

use App\Assets\LocalFindingSeverityChanger;
use App\Assets\LocalFindingStatusChanger;
use App\Assets\LocalFindingWorkItemService;
use App\Filament\Pages\ProfileIntegrationsPage;
use App\Filament\Resources\LocalFindingResource\Pages\ListLocalFindings;
use App\Filament\Resources\LocalFindingResource\Pages\ViewLocalFinding;
use App\Filament\Resources\LocalFindingResource\RelationManagers\CommentsRelationManager;
use App\Filament\Resources\LocalFindingResource\RelationManagers\WorkItemLinksRelationManager;
use App\Filament\Resources\LocalFindingResource\Support\LocalFindingTableQuery;
use App\Filament\Support\EventStateBadgeColor;
use App\Filament\Support\LocalFindingOwnerColumns;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\LocalFindingWorkItemLink;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\SecurityEvents\LinkCollector;
use App\SecurityEvents\LocalFindingLinkCatalog;
use App\Trackers\WorkItemFormOptions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Org-wide Trivy finding explorer (vulnerabilities and secrets), modeled on
 * SecurityEventResource (Alerts) — a list + view pair for data that was
 * previously only visible inline in the Local Findings relation manager.
 */
class LocalFindingResource extends Resource
{
    protected static ?string $model = LocalFinding::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bug-ant';

    protected static string|\UnitEnum|null $navigationGroup = 'Reader';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Local Findings';

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('alerts.view');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['owner', 'softwareAsset', 'softwareSystem', 'correlatedSecurityEvent', 'attachment', 'workItemLinks']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Finding')
                ->schema([
                    Grid::make(3)->schema([
                        // Field order mirrors SecurityEventResource's Alert Summary: title
                        // first, then the type/severity/state trio, then scope, timestamps,
                        // identity, and long text last — so the two detail pages read alike.
                        TextEntry::make('title')->label('Title')->wrap()->columnSpan(2),
                        TextEntry::make('kind')->label('Kind')->badge()->color(fn (string $state): string => match ($state) {
                            LocalFinding::KIND_SECRET => 'danger',
                            LocalFinding::KIND_CODE_QUALITY => 'info',
                            default => 'warning',
                        }),
                        TextEntry::make('_effective_severity')
                            ->label('Severity')
                            ->state(fn (LocalFinding $record): string => $record->overridden_severity !== null
                                ? "{$record->effectiveSeverityLabel()} (reported: {$record->severity})"
                                : $record->effectiveSeverityLabel())
                            ->badge()
                            ->color(fn (LocalFinding $record): string => LocalFinding::severityColor($record->effectiveSeverityLabel())),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (EventState|string $state): string => str($state instanceof EventState ? $state->value : $state)->replace('_', ' ')->title()->toString())
                            ->color(fn (EventState|string $state) => EventStateBadgeColor::for($state)),
                        TextEntry::make('rule_id')->label('Rule ID')->placeholder('-'),
                        TextEntry::make('softwareAsset.name')
                            ->label('Asset')
                            ->url(fn (LocalFinding $record): ?string => $record->softwareAsset
                                ? SoftwareAssetResource::getUrl('view', ['record' => $record->softwareAsset])
                                : null)
                            ->placeholder('-'),
                        TextEntry::make('softwareSystem.name')
                            ->label('System')
                            ->url(fn (LocalFinding $record): ?string => $record->softwareSystem
                                ? SoftwareSystemResource::getUrl('view', ['record' => $record->softwareSystem])
                                : null)
                            ->placeholder('-'),
                        TextEntry::make('_container')
                            ->label('Container')
                            ->state(fn (LocalFinding $record): ?string => $record->owner instanceof SecurityContainer ? $record->owner->name : null)
                            ->url(fn (LocalFinding $record): ?string => $record->owner instanceof SecurityContainer
                                ? SecurityContainerResource::getUrl('view', ['record' => $record->owner])
                                : null)
                            ->placeholder('-'),
                        TextEntry::make('correlated_security_event_id')
                            ->label('Correlated alert')
                            ->state(fn (LocalFinding $record): string => $record->correlated_security_event_id !== null ? '#' . $record->correlated_security_event_id : '-')
                            ->url(fn (LocalFinding $record): ?string => $record->correlated_security_event_id !== null
                                ? SecurityEventResource::getUrl('view', ['record' => $record->correlated_security_event_id])
                                : null)
                            ->color(fn (LocalFinding $record): string => $record->correlated_security_event_id !== null ? 'primary' : 'gray'),
                        TextEntry::make('first_seen_at')->label('First seen')->dateTime('d M Y H:i')->placeholder('-'),
                        TextEntry::make('last_seen_at')->label('Last seen')->since()->placeholder('-'),
                        TextEntry::make('_location')
                            ->label('Location')
                            ->state(fn (LocalFinding $record): string => $record->start_line !== null
                                ? "{$record->file_path}:{$record->start_line}"
                                : $record->file_path)
                            ->placeholder('-'),
                        TextEntry::make('_package')
                            ->label('Package')
                            ->state(fn (LocalFinding $record): ?string => $record->package_name !== null
                                ? trim("{$record->package_name} {$record->package_version}")
                                : null)
                            ->placeholder('-'),
                        TextEntry::make('description')->label('Description')->wrap()->placeholder('-')->columnSpanFull(),
                    ]),
                ]),

            self::linksSection(),
        ]);
    }

    /**
     * A compact catalog of every reference link for the finding — the same
     * "Links & References" section the alert view renders (see
     * SecurityEventResource::linksSection), built by LocalFindingLinkCatalog.
     */
    private static function linksSection(): Section
    {
        return Section::make('Links & References')
            ->collapsible()
            ->visible(fn (LocalFinding $record): bool => self::allLinkCatalogRows($record) !== [])
            ->schema([
                RepeatableEntry::make('_links')
                    ->hiddenLabel()
                    ->state(fn (LocalFinding $record): array => self::allLinkCatalogRows($record))
                    ->schema([
                        TextEntry::make('kind_label')
                            ->hiddenLabel()
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('label')
                            ->hiddenLabel()
                            ->wrap()
                            ->columnSpan(2),
                        TextEntry::make('url')
                            ->hiddenLabel()
                            ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Open' : '-')
                            ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                            ->openUrlInNewTab()
                            ->badge()
                            ->color('primary')
                            ->icon('heroicon-m-arrow-top-right-on-square'),
                    ])
                    ->columns(4),
            ]);
    }

    /**
     * @return list<array{label: string, kind: string, kind_label: string, url: string, external: bool}>
     */
    private static function allLinkCatalogRows(LocalFinding $record): array
    {
        return array_map(
            static fn (array $link): array => [
                'label' => $link['label'],
                'kind' => $link['kind'],
                'kind_label' => LinkCollector::kindLabel($link['kind']),
                'url' => $link['url'],
                'external' => $link['external'],
            ],
            app(LocalFindingLinkCatalog::class)->build($record),
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ...array_map(
                    fn (TextColumn $column): TextColumn => $column->toggleable(),
                    LocalFindingOwnerColumns::columns(),
                ),
                TextColumn::make('_effective_severity')
                    ->label('Severity')
                    ->state(fn (LocalFinding $record): string => $record->effectiveSeverityLabel())
                    ->badge()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(LocalFindingTableQuery::effectiveSeverityRankSql() . ' ' . ($direction === 'asc' ? 'ASC' : 'DESC')))
                    ->color(fn (LocalFinding $record): string => LocalFinding::severityColor($record->effectiveSeverityLabel())),
                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (EventState|string $state): string => str($state instanceof EventState ? $state->value : $state)->replace('_', ' ')->title()->toString())
                    ->color(fn (EventState|string $state) => EventStateBadgeColor::for($state)),
                TextColumn::make('kind')->badge()->sortable()->toggleable()->color(fn (string $state): string => match ($state) {
                    LocalFinding::KIND_SECRET => 'danger',
                    LocalFinding::KIND_CODE_QUALITY => 'info',
                    default => 'warning',
                }),
                TextColumn::make('title')->searchable()->sortable()->wrap()->grow(),
                TextColumn::make('file_path')->label('Location')
                    ->formatStateUsing(fn (LocalFinding $record): string => $record->start_line !== null
                        ? "{$record->file_path}:{$record->start_line}"
                        : $record->file_path)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('package_name')->label('Package')
                    ->formatStateUsing(fn (?string $state, LocalFinding $record): ?string => $state !== null
                        ? trim("{$state} {$record->package_version}")
                        : null)
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('correlated_security_event_id')
                    ->label('Correlated alert')
                    ->state(fn (LocalFinding $record): string => $record->correlated_security_event_id !== null ? '#' . $record->correlated_security_event_id : '-')
                    ->url(fn (LocalFinding $record): ?string => $record->correlated_security_event_id !== null
                        ? SecurityEventResource::getUrl('view', ['record' => $record->correlated_security_event_id])
                        : null)
                    ->sortable()
                    ->color(fn (LocalFinding $record): string => $record->correlated_security_event_id !== null ? 'primary' : 'gray'),
                TextColumn::make('work_item_state')
                    ->label('Tracker')
                    ->state(function (LocalFinding $record): ?string {
                        $link = $record->workItemLinks->first();

                        if (! $link) {
                            return null;
                        }

                        return $link->work_item_state ?? $link->work_item_id;
                    })
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('-')->sortable(),
                TextColumn::make('first_seen_at')->label('First seen')->dateTime('d M Y')->placeholder('-')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->multiple()
                    ->options([
                        LocalFinding::KIND_VULNERABILITY => 'Vulnerability',
                        LocalFinding::KIND_SECRET => 'Secret',
                        LocalFinding::KIND_CODE_QUALITY => 'Code Quality',
                    ])
                    ->query(fn (Builder $query, array $data) => LocalFindingTableQuery::applyKinds($query, self::stringArray($data['values'] ?? []))),
                SelectFilter::make('status')
                    ->multiple()
                    ->options(collect(EventState::cases())->mapWithKeys(fn (EventState $state) => [$state->value => str($state->value)->replace('_', ' ')->title()->toString()])->all())
                    ->query(fn (Builder $query, array $data) => LocalFindingTableQuery::applyStatuses($query, self::stringArray($data['values'] ?? []))),
                SelectFilter::make('severity')
                    ->label('Severity')
                    ->multiple()
                    ->options(collect(EventSeverity::cases())
                        ->mapWithKeys(fn (EventSeverity $severity) => [$severity->value => ucfirst($severity->value)])
                        ->put('unknown', 'Unknown')
                        ->all())
                    ->query(fn (Builder $query, array $data) => LocalFindingTableQuery::applyEffectiveSeverities($query, self::stringArray($data['values'] ?? []))),
                SelectFilter::make('asset_scope')
                    ->label('Asset')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::assetScopeOptions())
                    ->query(fn (Builder $query, array $data) => LocalFindingTableQuery::applyAssetScopes($query, self::stringArray($data['values'] ?? []))),
                SelectFilter::make('system_scope')
                    ->label('System')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::systemScopeOptions())
                    ->query(fn (Builder $query, array $data) => LocalFindingTableQuery::applySystemScopes($query, self::stringArray($data['values'] ?? []))),
                SelectFilter::make('container_scope')
                    ->label('Container')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::containerScopeOptions())
                    ->query(fn (Builder $query, array $data) => LocalFindingTableQuery::applyContainerScopes($query, self::stringArray($data['values'] ?? []))),
                SelectFilter::make('work_item_state')
                    ->label('Work item status')
                    ->multiple()
                    ->options(fn (): array => self::workItemStateOptions())
                    ->query(fn (Builder $query, array $data) => LocalFindingTableQuery::applyWorkItemStates($query, self::stringArray($data['values'] ?? []))),
                TernaryFilter::make('has_work_item')
                    ->label('Has work item')
                    ->queries(
                        true: fn (Builder $query) => LocalFindingTableQuery::applyHasWorkItem($query, true),
                        false: fn (Builder $query) => LocalFindingTableQuery::applyHasWorkItem($query, false),
                        blank: fn (Builder $query) => $query,
                    ),
                TernaryFilter::make('is_correlated')
                    ->label('Correlated to alert')
                    ->queries(
                        true: fn (Builder $query) => LocalFindingTableQuery::applyIsCorrelated($query, true),
                        false: fn (Builder $query) => LocalFindingTableQuery::applyIsCorrelated($query, false),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('changeStatus')
                        ->label('Change status')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (): bool => Gate::allows('alerts.edit'))
                        ->form(self::statusChangeForm())
                        ->action(function (LocalFinding $record, array $data): void {
                            /** @var User|null $user */
                            $user = Auth::user();

                            if ($user === null) {
                                abort(403);
                            }

                            app(LocalFindingStatusChanger::class)->change(
                                $record,
                                $user,
                                EventState::from((string) $data['new_status']),
                                (string) $data['comment'],
                            );

                            Notification::make()->title('Status changed')->success()->send();
                        }),
                    Action::make('changeSeverity')
                        ->label('Change severity')
                        ->icon('heroicon-o-adjustments-horizontal')
                        ->visible(fn (): bool => Gate::allows('alerts.edit'))
                        ->form(self::severityChangeForm())
                        ->action(function (LocalFinding $record, array $data): void {
                            /** @var User|null $user */
                            $user = Auth::user();

                            if ($user === null) {
                                abort(403);
                            }

                            app(LocalFindingSeverityChanger::class)->change(
                                $record,
                                $user,
                                EventSeverity::from((string) $data['new_severity']),
                                (string) $data['comment'],
                            );

                            Notification::make()->title('Severity changed')->success()->send();
                        }),
                    Action::make('createWorkItem')
                        ->label('Create work item')
                        ->icon('heroicon-o-ticket')
                        ->visible(fn (): bool => Gate::allows('work-items.create'))
                        ->form(fn (LocalFinding $record): array => app(WorkItemFormOptions::class)->createSchemaForFindings([$record]))
                        ->action(function (LocalFinding $record, array $data): void {
                            /** @var User|null $user */
                            $user = Auth::user();

                            if ($user === null) {
                                abort(403);
                            }

                            $trackerId = (string) $data['tracker'];
                            $missing = app(WorkItemFormOptions::class)->missingCredentialLabelsForTracker($trackerId);

                            if ($missing !== []) {
                                self::notifyMissingPersonalCredentials($trackerId, $missing);

                                return;
                            }

                            app(LocalFindingWorkItemService::class)->createForFindings(
                                findingIds: [$record->id],
                                userId: $user->id,
                                trackerId: $trackerId,
                                projectKey: (string) $data['project'],
                                itemType: (string) $data['item_type'],
                                labels: self::stringArray($data['labels'] ?? []),
                                priority: self::nullableString($data['priority'] ?? null),
                                assigneeId: self::nullableString($data['assignee_id'] ?? null),
                                parentId: self::nullableString($data['parent_id'] ?? null),
                            );

                            Notification::make()->title('Work item created')->success()->send();
                        }),
                    Action::make('linkExistingWorkItem')
                        ->label('Link existing work item')
                        ->icon('heroicon-o-link')
                        ->visible(fn (): bool => Gate::allows('work-items.link'))
                        ->form(fn (LocalFinding $record): array => app(WorkItemFormOptions::class)->linkSchemaForFindings([$record]))
                        ->action(function (LocalFinding $record, array $data): void {
                            /** @var User|null $user */
                            $user = Auth::user();

                            if ($user === null) {
                                abort(403);
                            }

                            $trackerId = (string) $data['tracker'];
                            $missing = app(WorkItemFormOptions::class)->missingCredentialLabelsForTracker($trackerId);

                            if ($missing !== []) {
                                self::notifyMissingPersonalCredentials($trackerId, $missing);

                                return;
                            }

                            try {
                                app(LocalFindingWorkItemService::class)->linkExisting(
                                    findingIds: [$record->id],
                                    userId: $user->id,
                                    trackerId: $trackerId,
                                    workItemId: (string) $data['selected_work_item'],
                                    projectKey: (string) ($data['project'] ?? ''),
                                );
                            } catch (\RuntimeException $exception) {
                                Notification::make()->title($exception->getMessage())->danger()->send();

                                return;
                            }

                            Notification::make()->title('Work item linked')->success()->send();
                        }),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Actions'),
            ])
            ->bulkActions([
                BulkAction::make('changeStatusBulk')
                    ->label('Change status (bulk)')
                    ->icon('heroicon-o-pencil-square')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => Gate::allows('alerts.bulk-edit'))
                    ->form(self::statusChangeForm())
                    ->action(function (Collection $records, array $data): void {
                        /** @var Collection<int, LocalFinding> $records */
                        /** @var User|null $user */
                        $user = Auth::user();

                        if ($user === null) {
                            abort(403);
                        }

                        $newStatus = EventState::from((string) $data['new_status']);
                        $comment = (string) $data['comment'];
                        $changer = app(LocalFindingStatusChanger::class);

                        foreach ($records as $record) {
                            $changer->change($record, $user, $newStatus, $comment);
                        }

                        Notification::make()->title('Status changed for selected findings')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('createGroupedWorkItem')
                    ->label('Create grouped work item')
                    ->icon('heroicon-o-ticket')
                    ->visible(fn (): bool => Gate::allows('work-items.create'))
                    ->form(fn (Collection $records): array => app(WorkItemFormOptions::class)->createSchemaForFindings(
                        array_values($records->filter(fn (mixed $record): bool => $record instanceof LocalFinding)->all())
                    ))
                    ->action(function (Collection $records, array $data): void {
                        /** @var Collection<int, LocalFinding> $records */
                        /** @var User|null $user */
                        $user = Auth::user();

                        if ($user === null) {
                            abort(403);
                        }

                        $trackerId = (string) $data['tracker'];
                        $missing = app(WorkItemFormOptions::class)->missingCredentialLabelsForTracker($trackerId);

                        if ($missing !== []) {
                            self::notifyMissingPersonalCredentials($trackerId, $missing);

                            return;
                        }

                        app(LocalFindingWorkItemService::class)->createForFindings(
                            findingIds: self::selectedFindingIds($records),
                            userId: $user->id,
                            trackerId: $trackerId,
                            projectKey: (string) $data['project'],
                            itemType: (string) $data['item_type'],
                            labels: self::stringArray($data['labels'] ?? []),
                            priority: self::nullableString($data['priority'] ?? null),
                            assigneeId: self::nullableString($data['assignee_id'] ?? null),
                            parentId: self::nullableString($data['parent_id'] ?? null),
                        );

                        Notification::make()->title('Grouped work item created')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('linkExistingWorkItemBulk')
                    ->label('Link existing')
                    ->icon('heroicon-o-link')
                    ->visible(fn (): bool => Gate::allows('work-items.link'))
                    ->form(fn (Collection $records): array => app(WorkItemFormOptions::class)->linkSchemaForFindings(
                        array_values($records->filter(fn (mixed $record): bool => $record instanceof LocalFinding)->all())
                    ))
                    ->action(function (Collection $records, array $data): void {
                        /** @var Collection<int, LocalFinding> $records */
                        /** @var User|null $user */
                        $user = Auth::user();

                        if ($user === null) {
                            abort(403);
                        }

                        $trackerId = (string) $data['tracker'];
                        $missing = app(WorkItemFormOptions::class)->missingCredentialLabelsForTracker($trackerId);

                        if ($missing !== []) {
                            self::notifyMissingPersonalCredentials($trackerId, $missing);

                            return;
                        }

                        try {
                            app(LocalFindingWorkItemService::class)->linkExisting(
                                findingIds: self::selectedFindingIds($records),
                                userId: $user->id,
                                trackerId: $trackerId,
                                workItemId: (string) $data['selected_work_item'],
                                projectKey: (string) ($data['project'] ?? ''),
                            );
                        } catch (\RuntimeException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Existing work item linked')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->deferFilters(false)
            ->recordUrl(fn (LocalFinding $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultPaginationPageOption(25)
            ->paginated([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [
            CommentsRelationManager::class,
            WorkItemLinksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocalFindings::route('/'),
            'view' => ViewLocalFinding::route('/{record}'),
        ];
    }

    /** @return list<string> */
    public static function stringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param  Collection<int, LocalFinding>  $records
     * @return list<int>
     */
    private static function selectedFindingIds(Collection $records): array
    {
        return array_values($records->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /** @param list<string> $missing */
    public static function notifyMissingPersonalCredentials(string $trackerId, array $missing): void
    {
        $fields = implode(', ', $missing);

        Notification::make()
            ->title('Personal tracker credentials required')
            ->body("{$trackerId} is missing personal credentials: {$fields}.")
            ->warning()
            ->actions([
                Action::make('openProfileIntegrations')
                    ->label('Open profile integrations')
                    ->url(ProfileIntegrationsPage::getUrl()),
            ])
            ->send();
    }

    /** @return array<int, string> */
    private static function assetScopeOptions(): array
    {
        return SoftwareAsset::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (SoftwareAsset $asset): array => [$asset->id => $asset->name])
            ->all();
    }

    /** @return array<int, string> */
    private static function systemScopeOptions(): array
    {
        return SoftwareSystem::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (SoftwareSystem $system): array => [$system->id => $system->name])
            ->all();
    }

    /** @return array<int, string> */
    private static function containerScopeOptions(): array
    {
        return SecurityContainer::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (SecurityContainer $container): array => [$container->id => $container->name])
            ->all();
    }

    /** @return array<string, string> */
    private static function workItemStateOptions(): array
    {
        $options = LocalFindingWorkItemLink::query()
            ->whereNotNull('work_item_state')
            ->distinct()
            ->orderBy('work_item_state')
            ->pluck('work_item_state', 'work_item_state')
            ->all();

        $options['__none__'] = 'Unknown';

        return $options;
    }

    /**
     * Build a URL to the local findings list with pre-applied filter state.
     *
     * The Filament table filter query parameter format (Livewire URL binding) is:
     *   tableFilters[{filter_name}][values][0]=value  (for SelectFilter with multiple())
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

    /** @return array<int, Select|Textarea> */
    public static function statusChangeForm(): array
    {
        return [
            Select::make('new_status')
                ->label('New status')
                ->required()
                ->options(fn (): array => collect(EventState::cases())
                    ->mapWithKeys(fn (EventState $state): array => [$state->value => str($state->value)->replace('_', ' ')->title()->toString()])
                    ->all()),
            Textarea::make('comment')
                ->label('Comment')
                ->required()
                ->minLength(10)
                ->rows(4),
        ];
    }

    /** @return array<int, Select|Textarea> */
    public static function severityChangeForm(): array
    {
        return [
            Select::make('new_severity')
                ->label('New severity')
                ->required()
                ->options(fn (): array => collect(EventSeverity::cases())
                    ->mapWithKeys(fn (EventSeverity $severity): array => [$severity->value => ucfirst($severity->value)])
                    ->all()),
            Textarea::make('comment')
                ->label('Comment')
                ->required()
                ->minLength(10)
                ->rows(4),
        ];
    }
}
