<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SoftwareSystemLinkResource\Pages\CreateSoftwareSystemLink;
use App\Filament\Resources\SoftwareSystemLinkResource\Pages\EditSoftwareSystemLink;
use App\Filament\Resources\SoftwareSystemLinkResource\Pages\ListSoftwareSystemLinks;
use App\Filament\Resources\SoftwareSystemLinkResource\Pages\ViewSoftwareSystemLink;
use App\Filament\Resources\SoftwareSystemLinkResource\RelationManagers\MembersRelationManager;
use App\Models\SoftwareSystemLink;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
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

    protected static ?int $navigationSort = 24;

    protected static ?string $navigationLabel = 'System links';

    public static function canViewAny(): bool
    {
        return Auth::user()?->can('admin.integrations') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->rows(4)
                ->nullable(),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Summary')
                ->schema([
                    TextEntry::make('name')
                        ->label('Name')
                        ->wrap(),
                    TextEntry::make('description')
                        ->label('Description')
                        ->wrap()
                        ->placeholder('-'),
                    TextEntry::make('members_count')
                        ->label('Member count')
                        ->state(fn (SoftwareSystemLink $record): int => $record->members()->count())
                        ->placeholder('-'),
                    TextEntry::make('created_at')
                        ->label('Created')
                        ->dateTime('d M Y H:i')
                        ->placeholder('-'),
                    TextEntry::make('updated_at')
                        ->label('Last updated')
                        ->since()
                        ->placeholder('-'),
                ])
                ->columns(3),
        ]);
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
            ->actions([
                EditAction::make(),
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
            'create' => CreateSoftwareSystemLink::route('/create'),
            'view' => ViewSoftwareSystemLink::route('/{record}'),
            'edit' => EditSoftwareSystemLink::route('/{record}/edit'),
        ];
    }
}
