<?php

namespace App\Filament\Pages;

use App\Filament\Support\EventSeverityBadgeColor;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sync\PushEventStatesJob;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class PendingSyncPage extends Page implements HasTable
{
    use InteractsWithTable {
        applySortingToTableQuery as private defaultApplySortingToTableQuery;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-on-square-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Sync';

    protected static ?string $navigationLabel = 'Pending Sync';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'sync/pending';

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->can('work-items.sync') && $user->can('sources.push-state');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SecurityEvent::query()
                    ->where('is_dirty', true)
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
                    ->color(fn (SecurityEvent $record): string => match ($this->enumString($record->state)) {
                        'open' => 'danger',
                        'resolved' => 'success',
                        'dismissed' => 'gray',
                        default => 'secondary',
                    })
                    ->placeholder('-'),
                TextColumn::make('pending_state')
                    ->label('Pending state')
                    ->badge()
                    ->color(fn (SecurityEvent $record): string => match ($this->enumString($record->pending_state)) {
                        'open' => 'danger',
                        'resolved' => 'success',
                        'dismissed' => 'gray',
                        default => 'secondary',
                    })
                    ->placeholder('-'),
                TextColumn::make('severity')
                    ->label('Current severity')
                    ->badge()
                    ->color(fn (SecurityEvent $record): string => EventSeverityBadgeColor::for($record->severity))
                    ->placeholder('-'),
                TextColumn::make('pending_severity')
                    ->label('Pending severity')
                    ->badge()
                    ->color(fn (SecurityEvent $record): string => EventSeverityBadgeColor::for($record->pending_severity))
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
            ->groups(['source_id'])
            ->defaultGroup('source_id')
            ->bulkActions([
                BulkAction::make('pushToSource')
                    ->label('Push to source')
                    ->icon('heroicon-o-arrow-up-on-square-stack')
                    ->requiresConfirmation()
                    ->modalHeading('Push selected alerts to source')
                    ->modalDescription('This will dispatch sync jobs for each selected alert grouped by source.')
                    ->action(function (Collection $records): void {
                        Gate::authorize('work-items.sync');
                        Gate::authorize('sources.push-state');

                        /** @var User|null $user */
                        $user = Auth::user();

                        if ($user === null) {
                            abort(403);
                        }

                        $records->groupBy('source_id')
                            ->each(function (Collection $group) use ($user): void {
                                $ids = $group->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
                                /** @var list<int> $ids */
                                PushEventStatesJob::dispatch($ids, $user->id);
                            });

                        Notification::make()->title('Selected alerts queued for sync')->success()->send();
                    }),
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

    /**
     * @param  Builder<SecurityEvent>  $query
     * @return Builder<SecurityEvent>
     */
    protected function applySortingToTableQuery(Builder $query): Builder
    {
        if ($this->getTableSortColumn()) {
            return $this->defaultApplySortingToTableQuery($query);
        }

        return $query->orderBy('source_id')->orderByDesc('updated_at');
    }

    private function enumString(mixed $state): string
    {
        if ($state instanceof \BackedEnum) {
            return (string) $state->value;
        }

        return is_string($state) ? $state : '';
    }
}
