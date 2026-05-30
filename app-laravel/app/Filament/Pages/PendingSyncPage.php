<?php

namespace App\Filament\Pages;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Sync\PushEventStatesJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class PendingSyncPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-on-square-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Sync';

    protected static ?string $navigationLabel = 'Pending Sync';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'sync/pending';

    protected static string $view = 'filament.pages.pending-sync-page';

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->can('work-items.sync') && $user->can('sources.push-state');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SecurityEvent::query()
                    ->where('is_dirty', true)
                    ->orderBy('source_id')
                    ->orderByDesc('updated_at')
            )
            ->columns([
                TextColumn::make('source_id')
                    ->label('Source')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('title')
                    ->label('Title')
                    ->wrap()
                    ->grow()
                    ->searchable(),
                TextColumn::make('state')
                    ->label('Current state')
                    ->badge()
                    ->color(fn (SecurityEvent $record): string => match ($record->state?->value) {
                        'open' => 'danger',
                        'resolved' => 'success',
                        'dismissed' => 'gray',
                        default => 'secondary',
                    })
                    ->placeholder('-'),
                TextColumn::make('pending_state')
                    ->label('Pending state')
                    ->badge()
                    ->color(fn (SecurityEvent $record): string => match ($record->pending_state?->value) {
                        'open' => 'danger',
                        'resolved' => 'success',
                        'dismissed' => 'gray',
                        default => 'secondary',
                    })
                    ->placeholder('-'),
                TextColumn::make('severity')
                    ->label('Current severity')
                    ->badge()
                    ->color(fn (SecurityEvent $record): string => match ($record->severity?->value) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'gray',
                        default => 'secondary',
                    })
                    ->placeholder('-'),
                TextColumn::make('pending_severity')
                    ->label('Pending severity')
                    ->badge()
                    ->color(fn (SecurityEvent $record): string => match ($record->pending_severity?->value) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'gray',
                        default => 'secondary',
                    })
                    ->placeholder('-'),
                TextColumn::make('pending_comment')
                    ->label('Comment')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Last edited')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('source_id')
            ->groups(['source_id'])
            ->defaultGroup('source_id')
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('pushToSource')
                        ->label('Push to source')
                        ->icon('heroicon-o-arrow-up-on-square-stack')
                        ->requiresConfirmation()
                        ->modalHeading('Push selected alerts to source')
                        ->modalDescription('This will dispatch sync jobs for each selected alert grouped by source.')
                        ->action(function (Collection $records): void {
                            Gate::authorize('work-items.sync');
                            Gate::authorize('sources.push-state');

                            $records->groupBy('source_id')
                                ->each(function (Collection $group): void {
                                    $ids = $group->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
                                    /** @var list<int> $ids */
                                    PushEventStatesJob::dispatch($ids);
                                });

                            Notification::make()->title('Selected alerts queued for sync')->success()->send();
                        }),
                ]),
            ])
            ->emptyStateHeading('No pending sync')
            ->emptyStateDescription('All alerts are up to date with their sources.')
            ->paginated([25, 50, 100]);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
