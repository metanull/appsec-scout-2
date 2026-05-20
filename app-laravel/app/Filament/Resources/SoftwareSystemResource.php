<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SoftwareSystemResource\Pages\ListSoftwareSystems;
use App\Filament\Resources\SoftwareSystemResource\Pages\ViewSoftwareSystem;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\ContainersRelationManager;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\LinksRelationManager;
use App\Models\SoftwareSystem;
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

    protected static ?string $navigationLabel = 'Software systems';

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
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'events as open_events_count' => fn (Builder $events) => $events->whereRaw("state = 'open'"),
                'events as critical_events_count' => fn (Builder $events) => $events->whereRaw("severity = 'critical'"),
                'events as high_events_count' => fn (Builder $events) => $events->whereRaw("severity = 'high'"),
                'events as medium_events_count' => fn (Builder $events) => $events->whereRaw("severity = 'medium'"),
            ]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('source_id')->label('Source')->badge(),
                TextColumn::make('open_events_count')->label('Open')->sortable(),
                TextColumn::make('critical_events_count')->label('Critical')->sortable(),
                TextColumn::make('high_events_count')->label('High')->sortable(),
                TextColumn::make('medium_events_count')->label('Medium')->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since(),
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
