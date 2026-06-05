<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RepositoryProviderResource\Pages\CreateRepositoryProvider;
use App\Filament\Resources\RepositoryProviderResource\Pages\EditRepositoryProvider;
use App\Filament\Resources\RepositoryProviderResource\Pages\ListRepositoryProviders;
use App\Models\Enums\RepositoryProviderType;
use App\Models\RepositoryProvider;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class RepositoryProviderResource extends Resource
{
    protected static ?string $model = RepositoryProvider::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 27;

    protected static ?string $navigationLabel = 'Repository providers';

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('admin.repository-providers');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('provider_type')
                ->label('Provider type')
                ->options(self::providerTypeOptions())
                ->required()
                ->searchable()
                ->preload(),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('base_url')
                ->label('Base URL')
                ->required()
                ->url()
                ->maxLength(2048)
                ->helperText('Use the provider root URL, such as https://dev.azure.com/acme or https://github.com/acme.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('repositoryMappings'))
            ->columns([
                TextColumn::make('provider_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (RepositoryProviderType $state): string => self::providerTypeLabel($state)),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->grow(),
                TextColumn::make('base_url')
                    ->label('Base URL')
                    ->url(fn (RepositoryProvider $record): string => $record->base_url)
                    ->openUrlInNewTab()
                    ->wrap(),
                TextColumn::make('repository_mappings_count')
                    ->label('Mappings')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->recordUrl(fn (RepositoryProvider $record): string => static::getUrl('edit', ['record' => $record]))
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepositoryProviders::route('/'),
            'create' => CreateRepositoryProvider::route('/create'),
            'edit' => EditRepositoryProvider::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    private static function providerTypeOptions(): array
    {
        return collect(RepositoryProviderType::cases())
            ->mapWithKeys(fn (RepositoryProviderType $type): array => [$type->value => self::providerTypeLabel($type)])
            ->all();
    }

    private static function providerTypeLabel(RepositoryProviderType $type): string
    {
        return match ($type) {
            RepositoryProviderType::AzureRepos => 'Azure Repos',
            RepositoryProviderType::GitHub => 'GitHub',
        };
    }
}
