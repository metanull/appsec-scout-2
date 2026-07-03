<?php

namespace App\Filament\Resources\SoftwareAssetResource\RelationManagers;

use App\Assets\SoftwareAssetService;
use App\Filament\Resources\SoftwareSystemResource;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SoftwareSystemsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'softwareSystems';

    protected static ?string $title = 'Software systems';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('alerts.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('containers'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->wrap()->grow(),
                TextColumn::make('source_id')->label('Source')->badge()->color('info'),
                TextColumn::make('containers_count')->label('Containers')->sortable(),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('-'),
            ])
            ->recordUrl(fn (SoftwareSystem $record): string => SoftwareSystemResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('linkSystem')
                    ->label('Link software system')
                    ->icon('heroicon-o-link')
                    ->visible(fn (): bool => $this->canMutate())
                    ->form([
                        Select::make('software_system_id')
                            ->label('Software system')
                            ->options(fn (): array => $this->unlinkedSystemOptions())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $user = Auth::user();

                        if (! $user instanceof User) {
                            abort(403);
                        }

                        $system = SoftwareSystem::query()->findOrFail((int) $data['software_system_id']);

                        app(SoftwareAssetService::class)->attach($this->assetRecord(), $system, $user);

                        Notification::make()->title('Software system linked')->success()->send();
                    }),
            ])
            ->actions([
                Action::make('unlink')
                    ->label('Unlink')
                    ->icon('heroicon-o-link-slash')
                    ->color('danger')
                    ->visible(fn (): bool => $this->canMutate())
                    ->requiresConfirmation()
                    ->modalHeading('Unlink software system')
                    ->modalDescription('This removes the software system from this asset without deleting it.')
                    ->action(function (SoftwareSystem $record): void {
                        $user = Auth::user();

                        if (! $user instanceof User) {
                            abort(403);
                        }

                        app(SoftwareAssetService::class)->detach($record, $user);

                        Notification::make()->title('Software system unlinked')->success()->send();
                    }),
            ])
            ->emptyStateDescription('No software systems linked yet.');
    }

    private function canMutate(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('context.curate');
    }

    private function assetRecord(): SoftwareAsset
    {
        $owner = $this->getOwnerRecord();

        if ($owner instanceof SoftwareAsset) {
            return $owner;
        }

        abort(500);
    }

    /** @return array<int, string> */
    private function unlinkedSystemOptions(): array
    {
        return SoftwareSystem::query()
            ->whereNull('software_asset_id')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (SoftwareSystem $system): array => [
                $system->id => sprintf('%s (%s)', $system->name, $system->source_id),
            ])
            ->all();
    }
}
