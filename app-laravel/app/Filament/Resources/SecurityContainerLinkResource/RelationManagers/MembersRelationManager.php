<?php

namespace App\Filament\Resources\SecurityContainerLinkResource\RelationManagers;

use App\Models\SecurityContainer;
use App\Models\SecurityContainerLink;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Members';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('alerts.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->orderBy('security_container_link_members.sort_order'))
            ->columns([
                TextColumn::make('name')->searchable()->wrap()->grow(),
                TextColumn::make('source_id')->label('Source')->badge(),
                TextColumn::make('kind')->badge()->placeholder('-'),
                TextColumn::make('pivot.sort_order')->label('Order'),
            ])
            ->headerActions([
                Action::make('addMember')
                    ->label('Add member')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->canMutate())
                    ->form([
                        Select::make('security_container_id')
                            ->label('Container')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => SecurityContainer::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all()),
                        TextInput::make('sort_order')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $container = SecurityContainer::query()->findOrFail((int) $data['security_container_id']);
                        $this->ownerLink()->addMember($container, (int) $data['sort_order']);
                    }),
            ])
            ->actions([
                Action::make('reorder')
                    ->label('Reorder')
                    ->icon('heroicon-o-arrows-up-down')
                    ->visible(fn (): bool => $this->canMutate())
                    ->fillForm(fn (SecurityContainer $record): array => [
                        'sort_order' => (int) ($record->pivot->sort_order ?? 0),
                    ])
                    ->form([
                        TextInput::make('sort_order')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                    ])
                    ->action(function (SecurityContainer $record, array $data): void {
                        $this->ownerLink()->reorderMember($record, (int) $data['sort_order']);
                    }),
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => $this->canMutate())
                    ->requiresConfirmation()
                    ->action(function (SecurityContainer $record): void {
                        $this->ownerLink()->removeMember($record);
                    }),
            ])
            ->paginated([10, 25, 50]);
    }

    private function canMutate(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasAnyRole(['Plan', 'Admin']);
    }

    private function ownerLink(): SecurityContainerLink
    {
        $ownerRecord = $this->getOwnerRecord();

        if (! $ownerRecord instanceof SecurityContainerLink) {
            abort(500);
        }

        return $ownerRecord;
    }
}
