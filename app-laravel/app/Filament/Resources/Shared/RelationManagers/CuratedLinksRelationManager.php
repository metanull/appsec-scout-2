<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shared\RelationManagers;

use App\CuratedLinks\CuratedLinkService;
use App\Models\CuratedLink;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\SecurityEvents\EventLinkCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CuratedLinksRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'curatedLinks';

    protected static ?string $title = 'Curated links';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('alerts.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Label')
                    ->wrap()
                    ->grow(),
                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => EventLinkCatalog::kindLabel($state)),
                TextColumn::make('url')
                    ->label('URL')
                    ->url(fn (CuratedLink $record): string => $record->url)
                    ->openUrlInNewTab()
                    ->wrap()
                    ->grow(),
                TextColumn::make('createdBy.name')
                    ->label('Created by')
                    ->placeholder('System'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since(),
            ])
            ->headerActions([
                Action::make('addCuratedLink')
                    ->label('Add curated link')
                    ->icon('heroicon-o-link')
                    ->visible(fn (): bool => $this->canMutate())
                    ->form($this->linkForm())
                    ->action(function (array $data): void {
                        $user = Auth::user();

                        if (! ($user instanceof User)) {
                            abort(403);
                        }

                        $owner = $this->curatedLinkOwner();

                        try {
                            app(CuratedLinkService::class)->create($owner, $user, $data);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title($this->validationMessage($exception))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Curated link added')->success()->send();
                    }),
            ])
            ->actions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil')
                    ->visible(fn (): bool => $this->canMutate())
                    ->fillForm(fn (CuratedLink $record): array => [
                        'label' => $record->label,
                        'url' => $record->url,
                        'kind' => $record->kind,
                    ])
                    ->form($this->linkForm())
                    ->action(function (CuratedLink $record, array $data): void {
                        $user = Auth::user();

                        if (! ($user instanceof User)) {
                            abort(403);
                        }

                        try {
                            app(CuratedLinkService::class)->update($record, $user, $data);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title($this->validationMessage($exception))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Curated link updated')->success()->send();
                    }),
                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => $this->canMutate())
                    ->action(function (CuratedLink $record): void {
                        $user = Auth::user();

                        if (! ($user instanceof User)) {
                            abort(403);
                        }

                        app(CuratedLinkService::class)->delete($record, $user);

                        Notification::make()->title('Curated link deleted')->success()->send();
                    }),
            ])
            ->emptyStateDescription('No curated links yet.')
            ->defaultSort('created_at', 'desc');
    }

    /** @return array<int, mixed> */
    private function linkForm(): array
    {
        return [
            TextInput::make('label')
                ->label('Label')
                ->required()
                ->maxLength(255),
            Select::make('kind')
                ->label('Kind')
                ->options($this->kindOptions())
                ->required()
                ->searchable()
                ->preload(),
            TextInput::make('url')
                ->label('URL')
                ->required()
                ->maxLength(2048),
        ];
    }

    /** @return array<string, string> */
    private function kindOptions(): array
    {
        return collect(CuratedLink::ALLOWED_KINDS)
            ->mapWithKeys(fn (string $kind): array => [$kind => EventLinkCatalog::kindLabel($kind)])
            ->all();
    }

    private function canMutate(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasAnyRole(['Plan', 'Admin']);
    }

    private function curatedLinkOwner(): SecurityEvent|SecurityContainer|SoftwareSystem
    {
        $owner = $this->getOwnerRecord();

        if ($owner instanceof SecurityEvent || $owner instanceof SecurityContainer || $owner instanceof SoftwareSystem) {
            return $owner;
        }

        abort(500);
    }

    private function validationMessage(ValidationException $exception): string
    {
        $errors = $exception->errors();

        return $errors['label'][0] ?? $errors['kind'][0] ?? $errors['url'][0] ?? 'Unable to save curated link.';
    }
}
