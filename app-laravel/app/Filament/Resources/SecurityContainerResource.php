<?php

namespace App\Filament\Resources;

use App\Filament\Exports\SecurityContainerExporter;
use App\Filament\Resources\SecurityContainerResource\Pages\ListSecurityContainers;
use App\Filament\Resources\SecurityContainerResource\Pages\ViewSecurityContainer;
use App\Filament\Resources\SecurityContainerResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\CuratedLinksRelationManager;
use App\Filament\Resources\Shared\RelationManagers\LocalFindingsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\RepositoryMappingsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\SoftwareComponentsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\TrackerProjectLinksRelationManager;
use App\Filament\Support\ContextQualityIndicatorSupport;
use App\Filament\Support\DateRangeFilters;
use App\Filament\Support\ListPageExportAction;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\SecurityEvents\EntityNavigationCatalog;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class SecurityContainerResource extends Resource
{
    use ContextQualityIndicatorSupport;

    protected static ?string $model = SecurityContainer::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|\UnitEnum|null $navigationGroup = 'Reader';

    protected static ?int $navigationSort = 14;

    protected static ?string $navigationLabel = 'Containers';

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
        return parent::getEloquentQuery()->with([
            'curatedLinks',
            'trackerProjectLinks',
            'repositoryMappings',
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Summary')
                ->schema([
                    TextEntry::make('name')
                        ->label('Name')
                        ->wrap(),
                    TextEntry::make('kind')
                        ->label('Kind')
                        ->badge()
                        ->color('gray')
                        ->placeholder('-'),
                    TextEntry::make('softwareSystem.name')
                        ->label('Software system')
                        ->placeholder('-'),
                    TextEntry::make('_system_link')
                        ->label('Link')
                        ->state(fn (SecurityContainer $record): string => $record->isSystemOverridden() ? 'Manual' : 'From source')
                        ->badge()
                        ->color(fn (string $state): string => $state === 'Manual' ? 'warning' : 'gray'),
                    TextEntry::make('sourceSoftwareSystem.name')
                        ->label('Source system')
                        ->visible(fn (SecurityContainer $record): bool => $record->isSystemOverridden())
                        ->placeholder('-'),
                    TextEntry::make('is_disabled')
                        ->label('Disabled upstream')
                        ->state(fn (SecurityContainer $record): string => $record->is_disabled ? 'Disabled' : 'Enabled')
                        ->badge()
                        ->color(fn (string $state): string => $state === 'Disabled' ? 'danger' : 'success')
                        ->visible(fn (SecurityContainer $record): bool => $record->kind === 'repository'),
                    TextEntry::make('last_commit_at')
                        ->label('Last commit')
                        ->dateTime()
                        ->placeholder('-')
                        ->visible(fn (SecurityContainer $record): bool => $record->kind === 'repository'),
                    TextEntry::make('last_commit_author')
                        ->label('Last commit author')
                        ->placeholder('-')
                        ->visible(fn (SecurityContainer $record): bool => $record->kind === 'repository'),
                    TextEntry::make('open_events_count')
                        ->label('Open alerts')
                        ->state(function (SecurityContainer $record): int {
                            return SecurityEvent::query()
                                ->where('container_id', $record->id)
                                ->where('state', EventState::Open->value)
                                ->count();
                        })
                        ->placeholder('-'),
                    TextEntry::make('critical_events_count')
                        ->label('Critical')
                        ->state(function (SecurityContainer $record): int {
                            return SecurityEvent::query()
                                ->where('container_id', $record->id)
                                ->where('severity', EventSeverity::Critical->value)
                                ->count();
                        })
                        ->placeholder('-'),
                    TextEntry::make('high_events_count')
                        ->label('High')
                        ->state(function (SecurityContainer $record): int {
                            return SecurityEvent::query()
                                ->where('container_id', $record->id)
                                ->where('severity', EventSeverity::High->value)
                                ->count();
                        })
                        ->placeholder('-'),
                    TextEntry::make('medium_events_count')
                        ->label('Medium')
                        ->state(function (SecurityContainer $record): int {
                            return SecurityEvent::query()
                                ->where('container_id', $record->id)
                                ->where('severity', EventSeverity::Medium->value)
                                ->count();
                        })
                        ->placeholder('-'),
                    TextEntry::make('first_seen_at')
                        ->label('First seen')
                        ->dateTime('d M Y H:i')
                        ->placeholder('-'),
                    TextEntry::make('last_seen_at')
                        ->label('Last seen')
                        ->since()
                        ->placeholder('-'),
                    TextEntry::make('updated_at')
                        ->label('Last updated')
                        ->since()
                        ->placeholder('-'),
                    TextEntry::make('removed_at')
                        ->label('Removed')
                        ->since()
                        ->placeholder('No - still present in the latest sync')
                        ->badge()
                        ->color(fn (SecurityContainer $record): string => $record->removed_at !== null ? 'danger' : 'success'),
                ])
                ->columns(4),

            Section::make('Context quality')
                ->schema([
                    TextEntry::make('_context_quality')
                        ->label('Quality indicators')
                        ->badge()
                        ->color(fn (SecurityContainer $record): string => self::qualityColor($record))
                        ->state(fn (SecurityContainer $record): string => self::qualitySummary($record))
                        ->wrap()
                        ->placeholder('-'),
                ]),

            Section::make('Navigation')
                ->visible(fn (SecurityContainer $record): bool => self::navigationRows($record) !== [])
                ->schema([
                    RepeatableEntry::make('_navigation_links')
                        ->label('')
                        ->state(fn (SecurityContainer $record): array => self::navigationRows($record))
                        ->schema([
                            TextEntry::make('label')
                                ->label('Label')
                                ->wrap(),
                            TextEntry::make('kind_label')
                                ->label('Kind')
                                ->badge()
                                ->color('gray'),
                            TextEntry::make('url')
                                ->label('URL')
                                ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                                ->openUrlInNewTab()
                                ->placeholder('-'),
                        ])
                        ->columns(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'events as open_events_count' => function (Builder $events) {
                    /** @var Builder<SecurityEvent> $events */
                    return $events->where('state', EventState::Open->value);
                },
                'events as critical_events_count' => function (Builder $events) {
                    /** @var Builder<SecurityEvent> $events */
                    return $events->where('severity', EventSeverity::Critical->value);
                },
                'events as high_events_count' => function (Builder $events) {
                    /** @var Builder<SecurityEvent> $events */
                    return $events->where('severity', EventSeverity::High->value);
                },
                'events as medium_events_count' => function (Builder $events) {
                    /** @var Builder<SecurityEvent> $events */
                    return $events->where('severity', EventSeverity::Medium->value);
                },
            ]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->wrap()->grow(),
                TextColumn::make('kind')->badge()->color('gray')->placeholder('-'),
                TextColumn::make('softwareSystem.name')
                    ->label('System')
                    ->searchable()
                    ->placeholder('-')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        SoftwareSystem::select('name')->whereColumn('software_systems.id', 'security_containers.software_system_id'),
                        $direction === 'desc' ? 'desc' : 'asc',
                    )),
                TextColumn::make('open_events_count')->label('Open')->sortable()->placeholder('-'),
                TextColumn::make('critical_events_count')
                    ->label('Critical')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('high_events_count')
                    ->label('High')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('medium_events_count')
                    ->label('Medium')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'info' : 'gray')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('-'),
                IconColumn::make('removed_at')
                    ->label('Removed')
                    ->boolean()
                    ->getStateUsing(fn (SecurityContainer $record): bool => $record->removed_at !== null)
                    ->trueColor('danger')
                    ->falseColor('success'),
                TextColumn::make('default_branch')
                    ->label('Default branch')
                    ->state(fn (SecurityContainer $record): ?string => $record->defaultBranch())
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_commit_at')
                    ->label('Last commit')
                    ->since()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_commit_author')
                    ->label('Last commit author')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_disabled')
                    ->label('Disabled')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                ListPageExportAction::make(SecurityContainerExporter::class),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Source')
                    ->multiple()
                    ->options(fn (): array => SoftwareSystem::query()->distinct()->orderBy('source_id')->pluck('source_id', 'source_id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['values'] ?? [],
                        fn (Builder $query, array $sources): Builder => $query->whereHas('sourceSoftwareSystem', fn (Builder $systems): Builder => $systems->whereIn('source_id', $sources)),
                    )),
                SelectFilter::make('kind')
                    ->options(fn (): array => SecurityContainer::query()->whereNotNull('kind')->distinct()->orderBy('kind')->pluck('kind', 'kind')->all()),
                TernaryFilter::make('removed_at')
                    ->label('Removed')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('removed_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('removed_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                TernaryFilter::make('is_disabled')->label('Disabled'),
                ...DateRangeFilters::for('last_commit_at', 'Last commit from', 'Last commit until'),
            ])
            ->recordUrl(fn (SecurityContainer $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('name')
            ->paginated([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
            CuratedLinksRelationManager::class,
            TrackerProjectLinksRelationManager::class,
            RepositoryMappingsRelationManager::class,
            AttachmentsRelationManager::class,
            SoftwareComponentsRelationManager::class,
            LocalFindingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSecurityContainers::route('/'),
            'view' => ViewSecurityContainer::route('/{record}'),
        ];
    }

    /**
     * @return list<array{label: string, url: string, kind: string, kind_label: string, external: bool}>
     */
    private static function navigationRows(SecurityContainer $record): array
    {
        return app(EntityNavigationCatalog::class)->buildForSecurityContainer($record);
    }
}
