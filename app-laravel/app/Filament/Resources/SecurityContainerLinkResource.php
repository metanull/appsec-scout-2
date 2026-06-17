<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SecurityContainerLinkResource\Pages\CreateSecurityContainerLink;
use App\Filament\Resources\SecurityContainerLinkResource\Pages\EditSecurityContainerLink;
use App\Filament\Resources\SecurityContainerLinkResource\Pages\ListSecurityContainerLinks;
use App\Filament\Resources\SecurityContainerLinkResource\Pages\ViewSecurityContainerLink;
use App\Filament\Resources\SecurityContainerLinkResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\SecurityContainerLinkResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Shared\RelationManagers\CuratedLinksRelationManager;
use App\Filament\Resources\Shared\RelationManagers\RepositoryMappingsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\TrackerProjectLinksRelationManager;
use App\Filament\Support\ContextQualityIndicatorSupport;
use App\Models\SecurityContainerLink;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SecurityContainerLinkResource extends Resource
{
    use ContextQualityIndicatorSupport;

    protected static ?string $model = SecurityContainerLink::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|\UnitEnum|null $navigationGroup = 'Reader';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Virtual containers';

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('alerts.view');
    }

    public static function canCreate(): bool
    {
        return static::canMutate();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canMutate();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canMutate();
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
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
                        ->state(fn (SecurityContainerLink $record): int => $record->members()->count()),
                    TextEntry::make('open_alerts_count')
                        ->label('Open alerts')
                        ->state(fn (SecurityContainerLink $record): int => $record->openAlertsCount()),
                    TextEntry::make('updated_at')
                        ->label('Last updated')
                        ->since()
                        ->placeholder('-'),
                ])
                ->columns(3),

            Section::make('Context quality')
                ->schema([
                    TextEntry::make('_context_quality')
                        ->label('Quality indicators')
                        ->badge()
                        ->color(fn (SecurityContainerLink $record): string => self::qualityColor($record))
                        ->state(fn (SecurityContainerLink $record): string => self::qualitySummary($record))
                        ->wrap()
                        ->placeholder('-'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('members')
            )
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->wrap()->grow(),
                TextColumn::make('description')->limit(80)->wrap()->placeholder('-'),
                TextColumn::make('members_count')->label('Members')->sortable(),
                TextColumn::make('open_alerts_count')
                    ->label('Open alerts')
                    ->state(fn (SecurityContainerLink $record): int => $record->openAlertsCount()),
                TextColumn::make('updated_at')->label('Updated')->since(),
            ])
            ->actions([
                EditAction::make()
                    ->visible(fn (): bool => static::canMutate()),
            ])
            ->recordUrl(fn (SecurityContainerLink $record): string => static::getUrl('view', ['record' => $record]))
            ->paginated([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            EventsRelationManager::class,
            TrackerProjectLinksRelationManager::class,
            RepositoryMappingsRelationManager::class,
            CuratedLinksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSecurityContainerLinks::route('/'),
            'create' => CreateSecurityContainerLink::route('/create'),
            'view' => ViewSecurityContainerLink::route('/{record}'),
            'edit' => EditSecurityContainerLink::route('/{record}/edit'),
        ];
    }

    public static function canMutate(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('context.curate');
    }
}
