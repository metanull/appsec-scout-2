<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Shared\RelationManagers\CuratedLinksRelationManager;
use App\Filament\Resources\Shared\RelationManagers\RepositoryMappingsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\TrackerProjectLinksRelationManager;
use App\Filament\Resources\SoftwareSystemResource\Pages\ListSoftwareSystems;
use App\Filament\Resources\SoftwareSystemResource\Pages\ViewSoftwareSystem;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\ContainersRelationManager;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\LinksRelationManager;
use App\Filament\Support\ContextQualityIndicatorSupport;
use App\Models\Enums\EventSeverity;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\SecurityEvents\EntityNavigationCatalog;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class SoftwareSystemResource extends Resource
{
    use ContextQualityIndicatorSupport;

    protected static ?string $model = SoftwareSystem::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Reader';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Software systems';

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
        return $schema->components([
            Section::make('Summary')
                ->schema([
                    TextEntry::make('name')
                        ->label('Name')
                        ->wrap(),
                    TextEntry::make('source_id')
                        ->label('Source')
                        ->badge()
                        ->color('info')
                        ->placeholder('-'),
                    TextEntry::make('open_events_count')
                        ->label('Open alerts')
                        ->state(fn (SoftwareSystem $record): int => $record->events()->open()->count())
                        ->placeholder('-'),
                    TextEntry::make('critical_events_count')
                        ->label('Critical')
                        ->state(fn (SoftwareSystem $record): int => $record->events()->withSeverity(EventSeverity::Critical)->count())
                        ->placeholder('-'),
                    TextEntry::make('high_events_count')
                        ->label('High')
                        ->state(fn (SoftwareSystem $record): int => $record->events()->withSeverity(EventSeverity::High)->count())
                        ->placeholder('-'),
                    TextEntry::make('medium_events_count')
                        ->label('Medium')
                        ->state(fn (SoftwareSystem $record): int => $record->events()->withSeverity(EventSeverity::Medium)->count())
                        ->placeholder('-'),
                    TextEntry::make('updated_at')
                        ->label('Last updated')
                        ->since()
                        ->placeholder('-'),
                ])
                ->columns(4),

            Section::make('Context quality')
                ->schema([
                    TextEntry::make('_context_quality')
                        ->label('Quality indicators')
                        ->badge()
                        ->color(fn (SoftwareSystem $record): string => self::qualityColor($record))
                        ->state(fn (SoftwareSystem $record): string => self::qualitySummary($record))
                        ->url(fn (SoftwareSystem $record): ?string => self::qualityUrl($record))
                        ->openUrlInNewTab()
                        ->wrap()
                        ->placeholder('-'),
                ]),

            Section::make('Navigation')
                ->visible(fn (SoftwareSystem $record): bool => self::navigationRows($record) !== [])
                ->schema([
                    RepeatableEntry::make('_navigation_links')
                        ->label('')
                        ->state(fn (SoftwareSystem $record): array => self::navigationRows($record))
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
                'events as open_events_count' => fn (Builder $events) => $events->open(),
                'events as critical_events_count' => fn (Builder $events) => $events->withSeverity(EventSeverity::Critical),
                'events as high_events_count' => fn (Builder $events) => $events->withSeverity(EventSeverity::High),
                'events as medium_events_count' => fn (Builder $events) => $events->withSeverity(EventSeverity::Medium),
            ]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->wrap()->grow(),
                TextColumn::make('source_id')->label('Source')->badge()->color('info'),
                TextColumn::make('open_events_count')->label('Open')->sortable()->placeholder('-'),
                TextColumn::make('critical_events_count')->label('Critical')->sortable()->placeholder('-'),
                TextColumn::make('high_events_count')->label('High')->sortable()->placeholder('-'),
                TextColumn::make('medium_events_count')->label('Medium')->sortable()->placeholder('-'),
                TextColumn::make('updated_at')->label('Updated')->since()->placeholder('-'),
            ])
            ->recordUrl(fn (SoftwareSystem $record): string => static::getUrl('view', ['record' => $record]))
            ->paginated([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
            ContainersRelationManager::class,
            CuratedLinksRelationManager::class,
            LinksRelationManager::class,
            TrackerProjectLinksRelationManager::class,
            RepositoryMappingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSoftwareSystems::route('/'),
            'view' => ViewSoftwareSystem::route('/{record}'),
        ];
    }

    /**
     * @return list<array{label: string, url: string, kind: string, kind_label: string, external: bool}>
     */
    private static function navigationRows(SoftwareSystem $record): array
    {
        return app(EntityNavigationCatalog::class)->buildForSoftwareSystem($record);
    }
}
