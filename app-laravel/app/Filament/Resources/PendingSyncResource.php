<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PendingSyncResource\Pages\ListPendingSync;
use App\Filament\Support\EventSeverityBadgeColor;
use App\Filament\Support\EventStateBadgeColor;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sync\PushEventStatesJob;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Alerts with an unpushed local change (state and/or severity), awaiting a
 * Push to source. A second, narrow Resource over SecurityEvent — index page
 * only, no create/edit/view pages of its own — so the list gets Filament's
 * usual URL-persisted filter/search/sort state and a recordUrl into the
 * alert's own SecurityEventResource view page, neither of which a plain
 * Page + InteractsWithTable provides.
 */
class PendingSyncResource extends Resource
{
    protected static ?string $model = SecurityEvent::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-on-square-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Sync';

    protected static ?string $navigationLabel = 'Pending Sync';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'sync/pending';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user instanceof User
            && $user->can('work-items.sync')
            && $user->can('sources.push-state');
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    /** @return Builder<SecurityEvent> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<SecurityEvent> $query */
        $query = parent::getEloquentQuery();

        return $query->where('is_dirty', true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
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
                    ->color(fn (SecurityEvent $record): string => self::stateBadgeColor($record->state))
                    ->placeholder('-'),
                TextColumn::make('pending_state')
                    ->label('Pending state')
                    ->badge()
                    ->color(fn (SecurityEvent $record): string => self::stateBadgeColor($record->pending_state))
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
            ->filters([
                SelectFilter::make('source_id')
                    ->label('Source')
                    ->options(fn (): array => static::getEloquentQuery()->distinct()->pluck('source_id', 'source_id')->all()),
                SelectFilter::make('pending_state')
                    ->label('Pending state')
                    ->options(collect(EventState::cases())->mapWithKeys(
                        fn (EventState $state): array => [$state->value => str($state->value)->replace('_', ' ')->title()->toString()],
                    )->all()),
            ])
            ->groups(['source_id'])
            ->defaultGroup('source_id')
            ->defaultSort('updated_at', 'desc')
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
            ->recordUrl(fn (SecurityEvent $record): string => SecurityEventResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No pending sync')
            ->emptyStateDescription('All alerts are up to date with their sources.')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPendingSync::route('/'),
        ];
    }

    /**
     * `pending_state` is nullable (an event can have a pending severity
     * change with no pending state change, or vice versa); a null value
     * must render as neutral, not as EventStateBadgeColor's "open" default.
     */
    private static function stateBadgeColor(mixed $state): string
    {
        $value = $state instanceof EventState ? $state->value : (is_string($state) ? $state : '');

        return $value === '' ? 'secondary' : EventStateBadgeColor::for($value);
    }
}
