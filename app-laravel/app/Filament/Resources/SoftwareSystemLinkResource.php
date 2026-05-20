<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SoftwareSystemLinkResource\Pages\ListSoftwareSystemLinks;
use App\Filament\Resources\SoftwareSystemLinkResource\Pages\ViewSoftwareSystemLink;
use App\Filament\Resources\SoftwareSystemLinkResource\RelationManagers\MembersRelationManager;
use App\Models\SoftwareSystemLink;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class SoftwareSystemLinkResource extends Resource
{
    protected static ?string $model = SoftwareSystemLink::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'System links';

    public static function canViewAny(): bool
    {
        return Auth::user()?->can('admin.integrations') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('members'))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('description')->limit(80),
                TextColumn::make('members_count')->label('Members')->sortable(),
                TextColumn::make('updated_at')->since(),
            ])
            ->recordUrl(fn (SoftwareSystemLink $record): string => static::getUrl('view', ['record' => $record]))
            ->paginated([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSoftwareSystemLinks::route('/'),
            'view' => ViewSoftwareSystemLink::route('/{record}'),
        ];
    }
}
