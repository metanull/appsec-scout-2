<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shared\RelationManagers;

use App\Models\Enums\RepositoryProviderType;
use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\SourceCode\RepositoryMappingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RepositoryMappingsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'repositoryMappings';

    protected static ?string $title = 'Repository mappings';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User && ($user->can('alerts.view') || $user->hasAnyRole(['Plan', 'Admin']));
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('repositoryProvider.name')
                    ->label('Provider')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('repository_name')
                    ->label('Repository')
                    ->searchable()
                    ->wrap()
                    ->grow(),
                TextColumn::make('repository_url')
                    ->label('Repository URL')
                    ->url(fn (RepositoryMapping $record): string => $record->repository_url)
                    ->openUrlInNewTab()
                    ->wrap()
                    ->grow(),
                TextColumn::make('default_branch')
                    ->label('Default branch')
                    ->placeholder('-'),
                TextColumn::make('path_prefix')
                    ->label('Path prefix')
                    ->placeholder('-'),
                TextColumn::make('createdBy.name')
                    ->label('Created by')
                    ->placeholder('System'),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since(),
            ])
            ->headerActions([
                Action::make('addRepositoryMapping')
                    ->label('Add mapping')
                    ->icon('heroicon-o-link')
                    ->visible(fn (): bool => $this->canMutate())
                    ->form($this->mappingForm())
                    ->action(function (array $data): void {
                        $user = Auth::user();

                        if (! $user instanceof User) {
                            abort(403);
                        }

                        try {
                            app(RepositoryMappingService::class)->create($this->ownerRecord(), $user, $data);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title($this->validationMessage($exception))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Repository mapping added')->success()->send();
                    }),
            ])
            ->actions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil')
                    ->visible(fn (): bool => $this->canMutate())
                    ->fillForm(fn (RepositoryMapping $record): array => [
                        'repository_provider_id' => $record->repository_provider_id,
                        'repository_name' => $record->repository_name,
                        'default_branch' => $record->default_branch,
                        'path_prefix' => $record->path_prefix,
                    ])
                    ->form($this->mappingForm())
                    ->action(function (RepositoryMapping $record, array $data): void {
                        try {
                            app(RepositoryMappingService::class)->update($record, $data);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title($this->validationMessage($exception))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Repository mapping updated')->success()->send();
                    }),
                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => $this->canMutate())
                    ->requiresConfirmation()
                    ->action(function (RepositoryMapping $record): void {
                        app(RepositoryMappingService::class)->delete($record);

                        Notification::make()->title('Repository mapping deleted')->success()->send();
                    }),
            ])
            ->emptyStateDescription('No repository mappings yet.')
            ->defaultSort('updated_at', 'desc');
    }

    /** @return array<int, mixed> */
    private function mappingForm(): array
    {
        return [
            Placeholder::make('owner_scope_note')
                ->label('Scope')
                ->content('Container mappings override system mappings for source-code link generation.'),
            Select::make('repository_provider_id')
                ->label('Repository provider')
                ->options($this->providerOptions())
                ->required()
                ->searchable()
                ->preload(),
            TextInput::make('repository_name')
                ->label('Repository name')
                ->required()
                ->maxLength(255)
                ->helperText('The repository URL is generated from the selected provider and repository name.'),
            TextInput::make('default_branch')
                ->label('Default branch')
                ->required()
                ->default('main')
                ->maxLength(255),
            TextInput::make('path_prefix')
                ->label('Path prefix')
                ->maxLength(255)
                ->nullable(),
            Placeholder::make('repository_url_preview')
                ->label('Repository URL')
                ->content(fn (Get $get): string => $this->repositoryUrlPreview($get)),
        ];
    }

    /** @return array<int|string, string> */
    private function providerOptions(): array
    {
        /** @var array<int|string, string> $options */
        $options = [];

        foreach (RepositoryProvider::query()->orderBy('name')->get() as $provider) {
            $options[(string) $provider->id] = sprintf('%s (%s)', $provider->name, $provider->getRawOriginal('provider_type'));
        }

        return $options;
    }

    private function canMutate(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasAnyRole(['Plan', 'Admin']);
    }

    /**
     * @return SoftwareSystem|SecurityContainer
     */
    private function ownerRecord(): Model
    {
        $ownerRecord = $this->getOwnerRecord();

        if ($ownerRecord instanceof SoftwareSystem || $ownerRecord instanceof SecurityContainer) {
            return $ownerRecord;
        }

        abort(500);
    }

    private function repositoryUrlPreview(Get $get): string
    {
        $providerId = $get('repository_provider_id');
        $repositoryName = is_string($get('repository_name')) ? trim($get('repository_name')) : '';

        if (! is_numeric($providerId) || $repositoryName === '') {
            return '—';
        }

        $provider = RepositoryProvider::query()->find((int) $providerId);

        if (! $provider instanceof RepositoryProvider || $provider->base_url === '') {
            return '—';
        }

        $normalizedRepositoryName = implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            array_values(array_filter(explode('/', trim(str_replace('\\', '/', $repositoryName))), static fn (string $segment): bool => $segment !== '' && $segment !== '.')),
        ));

        if ($normalizedRepositoryName === '') {
            return '—';
        }

        $normalizedBaseUrl = rtrim($provider->base_url, '/');
        $providerType = RepositoryProviderType::tryFrom((string) $provider->getRawOriginal('provider_type'));

        if (! $providerType instanceof RepositoryProviderType) {
            return '—';
        }

        return match ($providerType) {
            RepositoryProviderType::AzureRepos => $normalizedBaseUrl . '/_git/' . $normalizedRepositoryName,
            RepositoryProviderType::GitHub => $normalizedBaseUrl . '/' . $normalizedRepositoryName,
        };
    }

    private function validationMessage(ValidationException $exception): string
    {
        $errors = $exception->errors();

        return $errors['repository_provider_id'][0]
            ?? $errors['repository_name'][0]
            ?? $errors['default_branch'][0]
            ?? $errors['path_prefix'][0]
            ?? $errors['repository_url'][0]
            ?? 'Unable to save repository mapping.';
    }
}
