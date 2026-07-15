<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Shared\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\CuratedLinksRelationManager;
use App\Filament\Resources\Shared\RelationManagers\LocalFindingsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\RepositoryMappingsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\SoftwareComponentsRelationManager;
use App\Filament\Resources\SoftwareAssetResource\Pages\CreateSoftwareAsset;
use App\Filament\Resources\SoftwareAssetResource\Pages\EditSoftwareAsset;
use App\Filament\Resources\SoftwareAssetResource\Pages\ListSoftwareAssets;
use App\Filament\Resources\SoftwareAssetResource\Pages\ViewSoftwareAsset;
use App\Filament\Resources\SoftwareAssetResource\RelationManagers\SoftwareSystemsRelationManager;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\User;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SoftwareAssetResource extends Resource
{
    protected static ?string $model = SoftwareAsset::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static string|\UnitEnum|null $navigationGroup = 'Reader';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Software Assets';

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('alerts.view');
    }

    public static function canCreate(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('context.curate');
    }

    public static function canEdit(Model $record): bool
    {
        return static::canCreate();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canCreate();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->rows(3)
                ->maxLength(2000),
            KeyValue::make('metadata')
                ->label('Properties')
                ->keyLabel('Property')
                ->valueLabel('Value')
                ->reorderable()
                ->addActionLabel('Add property'),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount([
            'softwareSystems',
            'attachments',
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
                    TextEntry::make('software_systems_count')
                        ->label('Software systems')
                        ->placeholder('-'),
                    TextEntry::make('open_events_count')
                        ->label('Open alerts')
                        ->placeholder('-'),
                    TextEntry::make('critical_events_count')
                        ->label('Critical')
                        ->placeholder('-'),
                    TextEntry::make('high_events_count')
                        ->label('High')
                        ->placeholder('-'),
                    TextEntry::make('updated_at')
                        ->label('Last updated')
                        ->since()
                        ->placeholder('-'),
                ])
                ->columns(4),

            Section::make('Properties')
                ->visible(fn (SoftwareAsset $record): bool => filled($record->metadata))
                ->schema([
                    KeyValueEntry::make('metadata')
                        ->label(''),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->wrap()->grow(),
                TextColumn::make('software_systems_count')->label('Systems')->sortable(),
                TextColumn::make('open_events_count')->label('Open')->sortable()->placeholder('-'),
                TextColumn::make('critical_events_count')->label('Critical')->sortable()->placeholder('-'),
                TextColumn::make('high_events_count')->label('High')->sortable()->placeholder('-'),
                TextColumn::make('attachments_count')->label('Attachments')->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since()->placeholder('-'),
            ])
            ->recordUrl(fn (SoftwareAsset $record): string => static::getUrl('view', ['record' => $record]))
            ->paginated([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [
            SoftwareSystemsRelationManager::class,
            CuratedLinksRelationManager::class,
            RepositoryMappingsRelationManager::class,
            AttachmentsRelationManager::class,
            SoftwareComponentsRelationManager::class,
            LocalFindingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSoftwareAssets::route('/'),
            'create' => CreateSoftwareAsset::route('/create'),
            'view' => ViewSoftwareAsset::route('/{record}'),
            'edit' => EditSoftwareAsset::route('/{record}/edit'),
        ];
    }
}
