<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SecurityContainerResource\Pages\ListSecurityContainers;
use App\Filament\Resources\SecurityContainerResource\Pages\ViewSecurityContainer;
use App\Filament\Resources\SecurityContainerResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\CuratedLinksRelationManager;
use App\Filament\Resources\Shared\RelationManagers\RepositoryMappingsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\TrackerProjectLinksRelationManager;
use App\Filament\Support\ContextQualityIndicatorSupport;
use App\Models\Enums\EventState;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
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

class SecurityContainerResource extends Resource
{
    use ContextQualityIndicatorSupport;

    protected static ?string $model = SecurityContainer::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|\UnitEnum|null $navigationGroup = 'Reader';

    protected static ?int $navigationSort = 10;

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
        return $schema->components([
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
                    TextEntry::make('open_events_count')
                        ->label('Open alerts')
                        ->state(function (SecurityContainer $record): int {
                            return SecurityEvent::query()
                                ->where('container_id', $record->id)
                                ->where('state', EventState::Open->value)
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
            ]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->wrap()->grow(),
                TextColumn::make('kind')->badge()->color('gray')->placeholder('-'),
                TextColumn::make('softwareSystem.name')->label('System')->searchable()->placeholder('-'),
                TextColumn::make('open_events_count')->label('Open')->sortable()->placeholder('-'),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('-'),
            ])
            ->recordUrl(fn (SecurityContainer $record): string => static::getUrl('view', ['record' => $record]))
            ->paginated([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
            CuratedLinksRelationManager::class,
            TrackerProjectLinksRelationManager::class,
            RepositoryMappingsRelationManager::class,
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
