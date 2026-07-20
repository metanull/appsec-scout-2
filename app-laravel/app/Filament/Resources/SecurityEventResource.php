<?php

namespace App\Filament\Resources;

use App\Filament\Pages\ProfileIntegrationsPage;
use App\Filament\Resources\SecurityEventResource\Pages\ListSecurityEvents;
use App\Filament\Resources\SecurityEventResource\Pages\ViewSecurityEvent;
use App\Filament\Resources\SecurityEventResource\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\SecurityEventResource\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\SecurityEventResource\RelationManagers\CommentsRelationManager;
use App\Filament\Resources\SecurityEventResource\RelationManagers\WorkItemLinksRelationManager;
use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Filament\Resources\Shared\RelationManagers\CuratedLinksRelationManager;
use App\Filament\Support\ContextQualityIndicatorSupport;
use App\Filament\Support\EventSeverityBadgeColor;
use App\Filament\Support\EventStateBadgeColor;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\Models\WorkItemLink;
use App\SecurityEvents\EventLinkCatalog;
use App\SecurityEvents\SourceLinkHelper;
use App\Trackers\Registry as TrackerRegistry;
use App\Trackers\WorkItemFormOptions;
use App\Trackers\WorkItemService;
use App\Triage\SeverityChanger;
use App\Triage\StateChanger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SecurityEventResource extends Resource
{
    use ContextQualityIndicatorSupport;

    protected static ?string $model = SecurityEvent::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static string|\UnitEnum|null $navigationGroup = 'Reader';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Alerts';

    public static function canViewAny(): bool
    {
        return self::currentUserCan('alerts.view');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'curatedLinks',
            'softwareSystem',
            'softwareSystem.softwareAsset',
            'softwareSystem.repositoryMappings.repositoryProvider',
            'softwareSystem.curatedLinks',
            'container',
            'container.repositoryMappings.repositoryProvider',
            'container.curatedLinks',
            'workItemLinks',
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Alert Summary')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('title')
                            ->columnSpan(2)
                            ->wrap(),
                        TextEntry::make('type')
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(fn (EventType|string $state): string => str($state instanceof EventType ? $state->value : $state)->replace('_', ' ')->title()->toString()),
                        TextEntry::make('severity')
                            ->badge()
                            ->color(fn (EventSeverity|string $state): string => EventSeverityBadgeColor::for($state)),
                        TextEntry::make('state')
                            ->badge()
                            ->color(fn (EventState|string $state): string => EventStateBadgeColor::for($state))
                            ->formatStateUsing(fn (EventState|string $state): string => str($state instanceof EventState ? $state->value : $state)->replace('_', ' ')->title()->toString()),
                        TextEntry::make('source_id')
                            ->label('Source')
                            ->badge(),
                        TextEntry::make('softwareSystem.name')
                            ->label('System')
                            ->url(fn (SecurityEvent $record): ?string => $record->softwareSystem
                                ? SoftwareSystemResource::getUrl('view', ['record' => $record->softwareSystem])
                                : null)
                            ->placeholder('-'),
                        TextEntry::make('container.name')
                            ->label('Container')
                            ->url(fn (SecurityEvent $record): ?string => $record->container
                                ? SecurityContainerResource::getUrl('view', ['record' => $record->container])
                                : null)
                            ->placeholder('-'),
                        TextEntry::make('first_seen_at')
                            ->label('First seen')
                            ->dateTime('d M Y')
                            ->placeholder('-'),
                        TextEntry::make('last_seen_at')
                            ->label('Last seen')
                            ->dateTime('d M Y H:i')
                            ->placeholder('-'),
                        TextEntry::make('fingerprint')
                            ->placeholder('-'),
                        TextEntry::make('rule_id')
                            ->label('Rule ID')
                            ->placeholder('-'),
                        TextEntry::make('_readiness')
                            ->label('Readiness')
                            ->state(fn (SecurityEvent $record): array => self::readinessBadges($record))
                            ->badge()
                            ->color(fn (string $state): string => str_ends_with($state, '✗') ? 'warning' : 'success')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('_tags')
                            ->label('Tags')
                            ->state(function (SecurityEvent $record): array {
                                $tags = self::metadataArrayValue($record, 'tags');

                                return array_values(array_filter($tags, fn (mixed $tag): bool => is_string($tag) && $tag !== ''));
                            })
                            ->badge()
                            ->color('gray')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                ]),

            Section::make('Pending Sync')
                ->visible(fn (SecurityEvent $record): bool => $record->is_dirty || $record->pending_severity !== null)
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('pending_state')
                            ->label('Pending state')
                            ->badge()
                            ->color('warning')
                            ->formatStateUsing(fn (EventState|string|null $state): string => $state
                                ? str($state instanceof EventState ? $state->value : $state)->replace('_', ' ')->title()->toString()
                                : '—')
                            ->placeholder('-'),
                        TextEntry::make('pending_severity')
                            ->label('Pending severity')
                            ->badge()
                            ->color(fn (EventSeverity|string|null $state): string => match ($state instanceof EventSeverity ? $state->value : (is_string($state) ? $state : '')) {
                                EventSeverity::Critical->value => 'danger',
                                EventSeverity::High->value => 'warning',
                                EventSeverity::Medium->value => 'info',
                                EventSeverity::Low->value => 'gray',
                                default => 'warning',
                            })
                            ->placeholder('-'),
                        TextEntry::make('pending_comment')
                            ->label('Pending comment')
                            ->wrap()
                            ->placeholder('-'),
                    ]),
                ]),

            Section::make('Secret Details')
                ->visible(fn (SecurityEvent $record): bool => self::isEventType($record, EventType::Secret))
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('_detector')
                            ->label('Detector')
                            ->state(fn (SecurityEvent $record): ?string => self::metadataStringValue($record, 'detector'))
                            ->placeholder('-'),
                        TextEntry::make('_truncated_secret')
                            ->label('Truncated value')
                            ->state(fn (SecurityEvent $record): ?string => self::metadataStringValue($record, 'truncatedSecret'))
                            ->placeholder('-'),
                        TextEntry::make('_validation_fps')
                            ->label('Validation fingerprints')
                            ->state(fn (SecurityEvent $record): string => (string) count(self::metadataArrayValue($record, 'validationFingerprints'))),
                    ]),
                    RepeatableEntry::make('_occurrences')
                        ->label('Occurrences')
                        ->state(fn (SecurityEvent $record): array => self::buildOccurrenceRows($record))
                        ->schema([
                            TextEntry::make('file_path')
                                ->label('File')
                                ->placeholder('n/a'),
                            TextEntry::make('lines')
                                ->label('Lines')
                                ->placeholder('n/a'),
                            TextEntry::make('branch')
                                ->label('Branch')
                                ->placeholder('n/a'),
                            TextEntry::make('commit')
                                ->label('Commit')
                                ->placeholder('n/a'),
                        ])
                        ->columns(4),
                ]),

            Section::make('Dependency Details')
                ->visible(fn (SecurityEvent $record): bool => self::isEventType($record, EventType::Dependency))
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('_package')
                            ->label('Package')
                            ->state(function (SecurityEvent $record): ?string {
                                $package = self::metadataArrayValue($record, 'package');

                                if ($package === []) {
                                    return null;
                                }

                                return trim((string) ($package['name'] ?? '') . ' ' . (string) ($package['version'] ?? ''));
                            })
                            ->placeholder('-'),
                        TextEntry::make('_ecosystem')
                            ->label('Ecosystem')
                            ->state(function (SecurityEvent $record): ?string {
                                $package = self::metadataArrayValue($record, 'package');

                                return is_string($package['ecosystem'] ?? null) ? $package['ecosystem'] : null;
                            })
                            ->placeholder('-'),
                        TextEntry::make('_cve')
                            ->label('CVE')
                            ->state(fn (SecurityEvent $record): ?string => self::metadataStringValue($record, 'cve'))
                            ->url(fn (?string $state): ?string => filled($state) ? SourceLinkHelper::cveLinkUrl($state) : null)
                            ->openUrlInNewTab()
                            ->placeholder('-'),
                        TextEntry::make('_cvss')
                            ->label('CVSS')
                            ->state(function (SecurityEvent $record): ?string {
                                $cvss = self::metadataValue($record, 'cvss');

                                return $cvss !== null ? (string) $cvss : null;
                            })
                            ->placeholder('-'),
                        TextEntry::make('_fixed_in')
                            ->label('Fixed in')
                            ->state(fn (SecurityEvent $record): ?string => self::metadataStringValue($record, 'fixedInVersion'))
                            ->placeholder('-'),
                    ]),
                ]),

            Section::make('Code Location')
                ->visible(fn (SecurityEvent $record): bool => self::isEventType($record, EventType::Vulnerability) || self::isEventType($record, EventType::CodeQuality))
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('file_path')
                            ->label('File')
                            ->url(fn (?string $state, SecurityEvent $record): ?string => filled($record->version_control_url) ? $record->version_control_url : null)
                            ->openUrlInNewTab()
                            ->placeholder('-'),
                        TextEntry::make('_lines')
                            ->label('Lines')
                            ->state(fn (SecurityEvent $record): ?string => $record->start_line !== null
                                ? ($record->start_line === $record->end_line || $record->end_line === null
                                    ? (string) $record->start_line
                                    : $record->start_line . '–' . $record->end_line)
                                : null)
                            ->placeholder('-'),
                        TextEntry::make('rule_id')
                            ->label('Rule')
                            ->placeholder('-'),
                        TextEntry::make('_cwe')
                            ->label('CWE')
                            ->state(function (SecurityEvent $record): ?string {
                                $cwe = self::metadataValue($record, 'cwe');

                                return $cwe !== null ? (string) $cwe : null;
                            })
                            ->url(fn (?string $state): ?string => filled($state) ? SourceLinkHelper::cweLinkUrl($state) : null)
                            ->openUrlInNewTab()
                            ->placeholder('-'),
                        TextEntry::make('branch')
                            ->label('Branch')
                            ->placeholder('-'),
                        TextEntry::make('commit_sha')
                            ->label('Commit')
                            ->formatStateUsing(fn (?string $state): string => $state ? substr($state, 0, 12) : '-')
                            ->placeholder('-'),
                        TextEntry::make('snippet')
                            ->label('Snippet')
                            ->fontFamily('mono')
                            ->wrap()
                            ->columnSpanFull()
                            ->placeholder('-'),
                    ]),
                ]),

            Section::make('Posture')
                ->visible(fn (SecurityEvent $record): bool => self::isEventType($record, EventType::Misconfiguration) || self::isEventType($record, EventType::Iac) || self::isEventType($record, EventType::Posture))
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('_resource_type')
                            ->label('Resource type')
                            ->state(fn (SecurityEvent $record): ?string => self::metadataStringValue($record, 'resourceType'))
                            ->placeholder('-'),
                        TextEntry::make('_recommendation')
                            ->label('Recommendation')
                            ->state(fn (SecurityEvent $record): ?string => self::metadataStringValue($record, 'recommendation'))
                            ->wrap()
                            ->placeholder('-'),
                        TextEntry::make('_documentation_url')
                            ->label('Documentation')
                            ->state(fn (SecurityEvent $record): ?string => self::metadataStringValue($record, 'documentationUrl'))
                            ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                            ->openUrlInNewTab()
                            ->placeholder('-'),
                    ]),
                ]),

            Section::make('Raw Evidence')
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextEntry::make('_raw_evidence')
                        ->label('')
                        ->state(fn (SecurityEvent $record): string => json_encode(
                            self::buildRawEvidence($record),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        ) ?: '{}')
                        ->fontFamily('mono')
                        ->copyable()
                        ->columnSpanFull(),
                ]),

            Section::make('Remediation')
                ->schema([
                    TextEntry::make('remediation')
                        ->label('')
                        ->html()
                        ->state(fn (SecurityEvent $record): string => self::renderRemediation($record))
                        ->columnSpanFull(),
                ]),

            self::linksSection(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['workItemLinks', 'softwareSystem', 'softwareSystem.softwareAsset', 'container']))
            ->columns([
                TextColumn::make('softwareSystem.softwareAsset.name')
                    ->label('Asset')
                    ->placeholder('-')
                    ->toggleable()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        SoftwareAsset::select('name')->whereIn(
                            'software_assets.id',
                            SoftwareSystem::select('software_asset_id')->whereColumn('software_systems.id', 'security_events.software_system_id'),
                        ),
                        $direction === 'desc' ? 'desc' : 'asc',
                    )),
                TextColumn::make('softwareSystem.name')
                    ->label('System')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('container.name')
                    ->label('Container')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('severity')
                    ->badge()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                        "CASE severity WHEN 'critical' THEN 5 WHEN 'high' THEN 4 WHEN 'medium' THEN 3 WHEN 'low' THEN 2 WHEN 'informational' THEN 1 ELSE 0 END " . ($direction === 'asc' ? 'ASC' : 'DESC')
                    ))
                    ->color(fn (EventSeverity|string $state) => EventSeverityBadgeColor::for($state)),
                TextColumn::make('state')
                    ->badge()
                    ->sortable()
                    ->color(fn (EventState|string $state) => EventStateBadgeColor::for($state)),
                TextColumn::make('is_dirty')
                    ->label('Sync')
                    ->state(fn (SecurityEvent $record): ?string => $record->is_dirty ? 'Pending' : null)
                    ->badge()
                    ->color('warning')
                    ->placeholder('-'),
                TextColumn::make('source_id')->label('Source')->badge()->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (EventType|string $state): string => str($state instanceof EventType ? $state->value : $state)->replace('_', ' ')->title()->toString())
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
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
                    ->since()
                    ->placeholder('-')
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
                SelectFilter::make('asset_scope')
                    ->label('Asset')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::assetScopeOptions())
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applyAssetScopes($query, self::stringArray($data['values'] ?? []))),
                SelectFilter::make('system_scope')
                    ->label('System')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::systemScopeOptions())
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applySystemScopes($query, self::stringArray($data['values'] ?? []))),
                SelectFilter::make('container_scope')
                    ->label('Container')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::containerScopeOptions())
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applyContainerScopes($query, self::stringArray($data['values'] ?? []))),
                SelectFilter::make('type')
                    ->multiple()
                    ->options(collect(EventType::cases())->mapWithKeys(fn (EventType $type) => [$type->value => str($type->value)->replace('_', ' ')->title()->toString()])->all())
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applyTypes($query, self::stringArray($data['values'] ?? []))),
                SelectFilter::make('work_item_state')
                    ->label('Work item status')
                    ->multiple()
                    ->options(fn (): array => self::workItemStateOptions())
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applyWorkItemStates($query, self::stringArray($data['values'] ?? []))),
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
                        true: function (Builder $query) {
                            /** @var Builder<SecurityEvent> $query */
                            return $query->where('is_dirty', true);
                        },
                        false: function (Builder $query) {
                            /** @var Builder<SecurityEvent> $query */
                            return $query->where('is_dirty', false);
                        },
                        blank: fn (Builder $query) => $query,
                    ),
                SelectFilter::make('tags')
                    ->multiple()
                    ->options(fn () => self::availableTags())
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applyTags($query, self::stringArray($data['values'] ?? []))),
                Filter::make('work_item')
                    ->form([
                        Select::make('tracker_id')
                            ->label('Tracker')
                            ->options(fn (): array => collect(app(TrackerRegistry::class)->all())
                                ->mapWithKeys(fn ($t): array => [$t->id() => $t->displayName()])
                                ->all())
                            ->placeholder('Any tracker'),
                        TextInput::make('work_item_id')
                            ->label('Work item ID')
                            ->placeholder('e.g. PROJ-123')
                            ->maxLength(200),
                    ])
                    ->query(fn (Builder $query, array $data) => SecurityEventTableQuery::applyWorkItem(
                        $query,
                        is_string($data['tracker_id'] ?? null) ? $data['tracker_id'] : null,
                        is_string($data['work_item_id'] ?? null) ? $data['work_item_id'] : null,
                    ))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        $tracker = is_string($data['tracker_id'] ?? null) ? trim($data['tracker_id']) : '';
                        $itemId = is_string($data['work_item_id'] ?? null) ? trim($data['work_item_id']) : '';

                        if ($tracker !== '') {
                            $indicators[] = "Tracker: {$tracker}";
                        }

                        if ($itemId !== '') {
                            $indicators[] = "Work item: {$itemId}";
                        }

                        return $indicators;
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

                            $trackerId = (string) $data['tracker'];
                            $missing = app(WorkItemFormOptions::class)->missingCredentialLabelsForTracker($trackerId);

                            if ($missing !== []) {
                                self::notifyMissingPersonalCredentials($trackerId, $missing);

                                return;
                            }

                            app(WorkItemService::class)->createForEvents(
                                eventIds: [$record->id],
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
                        ->visible(fn (): bool => self::currentUserCan('work-items.link'))
                        ->form(fn (SecurityEvent $record): array => app(WorkItemFormOptions::class)->linkSchema([$record]))
                        ->action(function (SecurityEvent $record, array $data): void {
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

                            app(WorkItemService::class)->linkExisting(
                                eventIds: [$record->id],
                                userId: $user->id,
                                trackerId: $trackerId,
                                workItemId: (string) $data['selected_work_item'],
                                projectKey: (string) ($data['project'] ?? ''),
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
                    ->form(fn (Collection $records): array => app(WorkItemFormOptions::class)->createSchema(
                        array_values($records->filter(fn (mixed $record): bool => $record instanceof SecurityEvent)->all())
                    ))
                    ->action(function (Collection $records, array $data): void {
                        /** @var Collection<int, SecurityEvent> $records */
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

                        app(WorkItemService::class)->createForEvents(
                            eventIds: array_values($records->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()),
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
                    ->visible(fn (): bool => self::currentUserCan('work-items.link'))
                    ->form(fn (Collection $records): array => app(WorkItemFormOptions::class)->linkSchema(
                        array_values($records->filter(fn (mixed $record): bool => $record instanceof SecurityEvent)->all())
                    ))
                    ->action(function (Collection $records, array $data): void {
                        /** @var Collection<int, SecurityEvent> $records */
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

                        app(WorkItemService::class)->linkExisting(
                            eventIds: array_values($records->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()),
                            userId: $user->id,
                            trackerId: $trackerId,
                            workItemId: (string) $data['selected_work_item'],
                            projectKey: (string) ($data['project'] ?? ''),
                        );

                        Notification::make()->title('Existing work item linked')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->recordUrl(fn (SecurityEvent $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultPaginationPageOption(25)
            ->paginated([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [
            CommentsRelationManager::class,
            CuratedLinksRelationManager::class,
            WorkItemLinksRelationManager::class,
            AttachmentsRelationManager::class,
            AuditHistoryRelationManager::class,
        ];
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

    /** @return array<string, mixed> */
    private static function metadata(SecurityEvent $record): array
    {
        $metadata = $record->getAttribute('metadata');

        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && $metadata !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private static function metadataValue(SecurityEvent $record, string $key): mixed
    {
        $metadata = self::metadata($record);

        return $metadata[$key] ?? null;
    }

    /** @return array<int|string, mixed> */
    private static function metadataArrayValue(SecurityEvent $record, string $key): array
    {
        $value = self::metadataValue($record, $key);

        return is_array($value) ? $value : [];
    }

    private static function metadataStringValue(SecurityEvent $record, string $key): ?string
    {
        $value = self::metadataValue($record, $key);

        return is_string($value) ? $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /** @param list<string> $missing */
    private static function notifyMissingPersonalCredentials(string $trackerId, array $missing): void
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

    /** @return array<string, string> */
    private static function workItemStateOptions(): array
    {
        $options = WorkItemLink::query()
            ->whereNotNull('work_item_state')
            ->distinct()
            ->orderBy('work_item_state')
            ->pluck('work_item_state', 'work_item_state')
            ->all();

        $options['__none__'] = 'Unknown';

        return $options;
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

    /**
     * A single, compact catalog of every reference link for the alert.
     *
     * Replaces five per-kind sections (each of which rendered every link as a
     * full Label / Kind / URL card) with one collapsible section that lists
     * every link as a single compact row: a kind badge, the label, and the
     * long upstream URL collapsed down to a single "Open" affordance.
     */
    private static function linksSection(): Section
    {
        return Section::make('Links & References')
            ->collapsible()
            ->visible(fn (SecurityEvent $record): bool => self::allLinkCatalogRows($record) !== [])
            ->schema([
                RepeatableEntry::make('_links')
                    ->hiddenLabel()
                    ->state(fn (SecurityEvent $record): array => self::allLinkCatalogRows($record))
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
    private static function allLinkCatalogRows(SecurityEvent $record): array
    {
        return array_map(
            static fn (array $link): array => [
                'label' => $link['label'],
                'kind' => $link['kind'],
                'kind_label' => EventLinkCatalog::kindLabel($link['kind']),
                'url' => $link['url'],
                'external' => $link['external'],
            ],
            app(EventLinkCatalog::class)->build($record),
        );
    }

    /**
     * Compact readiness signals (repository / tracker / source-URL mapping)
     * rendered as inline badges on the Alert Summary. A trailing ✓/✗ encodes
     * the state so the badge colour can be derived without a sibling lookup.
     *
     * @return list<string>
     */
    private static function readinessBadges(SecurityEvent $record): array
    {
        return array_map(
            static fn (array $indicator): string => $indicator['label'] . ' ' . ($indicator['color'] === 'success' ? '✓' : '✗'),
            self::qualityIndicators($record),
        );
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

    private static function isEventType(SecurityEvent $record, EventType $type): bool
    {
        $current = $record->getAttribute('type');

        return ($current instanceof EventType ? $current : EventType::tryFrom((string) $current)) === $type;
    }

    /**
     * @return list<array{file_path: string, lines: string, branch: string, commit: string}>
     */
    private static function buildOccurrenceRows(SecurityEvent $record): array
    {
        /** @var array<string, mixed>|null $metadata */
        $metadata = $record->getAttribute('metadata');

        if (! is_array($metadata)) {
            return [];
        }

        $raw = $metadata['occurrences'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $rows = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $filePath = is_string($item['filePath'] ?? null) ? (string) $item['filePath'] : (is_string($item['file_path'] ?? null) ? (string) $item['file_path'] : 'n/a');
            $startLine = $item['startLine'] ?? $item['start_line'] ?? 'n/a';
            $endLine = $item['endLine'] ?? $item['end_line'] ?? 'n/a';
            $ref = is_string($item['ref'] ?? null) ? ltrim((string) $item['ref'], 'refs/heads/') : '';
            $commit = is_string($item['commitSha'] ?? $item['commit_sha'] ?? null)
                ? substr((string) ($item['commitSha'] ?? $item['commit_sha']), 0, 8)
                : '';

            $lines = (string) $startLine;
            if ((string) $endLine !== (string) $startLine && (string) $endLine !== 'n/a') {
                $lines .= '–' . $endLine;
            }

            $rows[] = [
                'file_path' => $filePath,
                'lines' => $lines,
                'branch' => $ref !== '' ? $ref : 'n/a',
                'commit' => $commit !== '' ? $commit : 'n/a',
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildRawEvidence(SecurityEvent $record): array
    {
        /** @var array<string, mixed>|null $metadata */
        $metadata = $record->getAttribute('metadata');
        $sourceDataRaw = $record->getAttribute('source_data');

        $sourceData = null;
        if (is_string($sourceDataRaw) && $sourceDataRaw !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($sourceDataRaw, true);
            $sourceData = is_array($decoded) ? $decoded : ['_raw' => Str::limit($sourceDataRaw, 4096)];
        } elseif (is_array($sourceDataRaw)) {
            $sourceData = $sourceDataRaw;
        }

        $type = $record->getAttribute('type');
        $severity = $record->getAttribute('severity');
        $state = $record->getAttribute('state');
        $pendingState = $record->getAttribute('pending_state');
        $pendingSeverity = $record->getAttribute('pending_severity');
        $syncedAt = $record->getAttribute('synced_at');

        return [
            'event' => [
                'id' => $record->id,
                'source_id' => $record->source_id,
                'source_event_id' => $record->source_event_id,
                'type' => $type instanceof EventType ? $type->value : (is_string($type) ? $type : null),
                'severity' => $severity instanceof EventSeverity ? $severity->value : (is_string($severity) ? $severity : null),
                'state' => $state instanceof EventState ? $state->value : (is_string($state) ? $state : null),
                'is_dirty' => $record->is_dirty,
                'pending_state' => $pendingState instanceof EventState ? $pendingState->value : null,
                'pending_severity' => $pendingSeverity instanceof EventSeverity ? $pendingSeverity->value : null,
                'rule_id' => $record->rule_id,
                'fingerprint' => $record->fingerprint,
                'synced_at' => $syncedAt instanceof \DateTimeInterface ? $syncedAt->format('c') : null,
            ],
            'metadata' => is_array($metadata) ? self::redactArray($metadata) : null,
            'source_data' => is_array($sourceData) ? self::redactArray($sourceData) : null,
        ];
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
            } elseif (self::isSensitiveKey((string) $key)) {
                $result[$key] = '***REDACTED***';
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (['token', 'secret', 'password', 'passwd', 'key', 'pat', 'authorization', 'credential', 'private'] as $sensitive) {
            if (str_contains($lower, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private static function renderRemediation(SecurityEvent $record): string
    {
        $markdown = $record->remediation;

        if (! is_string($markdown) || trim($markdown) === '') {
            return '<p class="fi-in-placeholder">No remediation guidance available.</p>';
        }

        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
