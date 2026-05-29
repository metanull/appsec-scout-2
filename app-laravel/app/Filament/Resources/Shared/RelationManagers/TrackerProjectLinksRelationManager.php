<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shared\RelationManagers;

use App\Models\User;
use App\Trackers\Registry as TrackerRegistry;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class TrackerProjectLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'trackerProjectLinks';

    protected static ?string $title = 'Tracker project links';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('alerts.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tracker_id')
                    ->label('Tracker')
                    ->badge(),
                TextColumn::make('project_key')
                    ->label('Project key')
                    ->searchable(),
                TextColumn::make('project_name')
                    ->label('Project name')
                    ->placeholder('\u2014'),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                TextColumn::make('createdBy.name')
                    ->label('Created by')
                    ->placeholder('System'),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add project link')
                    ->visible(fn (): bool => $this->canMutate())
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by_user_id'] = Auth::id();

                        return $data;
                    })
                    ->form($this->linkForm()),
            ])
            ->actions([
                EditAction::make()
                    ->visible(fn (): bool => $this->canMutate())
                    ->form($this->linkForm()),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->canMutate())
                    ->after(function (): void {
                        Notification::make()->title('Project link removed')->success()->send();
                    }),
            ])
            ->emptyStateDescription('No tracker project links yet.');
    }

    /** @return array<int, mixed> */
    private function linkForm(): array
    {
        return [
            Select::make('tracker_id')
                ->label('Tracker')
                ->options($this->trackerOptions())
                ->required(),
            TextInput::make('project_key')
                ->label('Project key')
                ->required()
                ->maxLength(100),
            TextInput::make('project_name')
                ->label('Project name')
                ->maxLength(255)
                ->nullable(),
            Toggle::make('is_default')
                ->label('Default project for this owner'),
        ];
    }

    /** @return array<string, string> */
    private function trackerOptions(): array
    {
        $options = [];

        foreach (app(TrackerRegistry::class)->all() as $tracker) {
            $options[$tracker->id()] = $tracker->displayName();
        }

        return $options;
    }

    private function canMutate(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->hasRole('Admin') || $user->can('work-items.link');
    }
}
