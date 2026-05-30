<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Shared\RelationManagers\TrackerProjectLinksRelationManager;
use App\Filament\Resources\SoftwareSystemResource\Pages\ListSoftwareSystems;
use App\Filament\Resources\SoftwareSystemResource\Pages\ViewSoftwareSystem;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\ContainersRelationManager;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\LinksRelationManager;
use App\Models\SoftwareSystem;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class SoftwareSystemResource extends Resource
{
    protected static ?string $model = SoftwareSystem::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Reader';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Software systems';

    public static function canViewAny(): bool
    {
        return Auth::user()?->can('alerts.view') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
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
                        ->state(fn (SoftwareSystem $record): int => $record->events()->whereRaw("state = 'open'")->count())
                        ->placeholder('-'),
                    TextEntry::make('critical_events_count')
                        ->label('Critical')
                        ->state(fn (SoftwareSystem $record): int => $record->events()->whereRaw("severity = 'critical'")->count())
                        ->placeholder('-'),
                    TextEntry::make('high_events_count')
                        ->label('High')
                        ->state(fn (SoftwareSystem $record): int => $record->events()->whereRaw("severity = 'high'")->count())
                        ->placeholder('-'),
                    TextEntry::make('medium_events_count')
                        ->label('Medium')
                        ->state(fn (SoftwareSystem $record): int => $record->events()->whereRaw("severity = 'medium'")->count())
                        ->placeholder('-'),
                    TextEntry::make('updated_at')
                        ->label('Last updated')
                        ->since()
                        ->placeholder('-'),
                ])
                ->columns(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'events as open_events_count' => fn (Builder $events) => $events->whereRaw("state = 'open'"),
                'events as critical_events_count' => fn (Builder $events) => $events->whereRaw("severity = 'critical'"),
                'events as high_events_count' => fn (Builder $events) => $events->whereRaw("severity = 'high'"),
                'events as medium_events_count' => fn (Builder $events) => $events->whereRaw("severity = 'medium'"),
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
            LinksRelationManager::class,
            TrackerProjectLinksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSoftwareSystems::route('/'),
            'view' => ViewSoftwareSystem::route('/{record}'),
        ];
    }
}
