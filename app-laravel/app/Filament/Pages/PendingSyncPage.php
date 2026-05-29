<?php

namespace App\Filament\Pages;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Sync\PendingSyncQuery;
use App\Sync\PushEventStatesJob;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class PendingSyncPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-on-square-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Sync';

    protected static ?string $navigationLabel = 'Pending Sync';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'sync/pending';

    protected string $view = 'filament.pages.pending-sync-page';

    /** @var list<int> */
    public array $selectedEventIds = [];

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->can('work-items.sync') && $user->can('sources.push-state');
    }

    /** @return Collection<string, Collection<int, array<string, mixed>>> */
    public function groupedEvents(): Collection
    {
        return app(PendingSyncQuery::class)->grouped();
    }

    public function pushSelected(): void
    {
        Gate::authorize('work-items.sync');
        Gate::authorize('sources.push-state');

        $eventIds = array_values(array_unique(array_map('intval', $this->selectedEventIds)));

        if ($eventIds === []) {
            Notification::make()->title('Select at least one pending alert')->warning()->send();

            return;
        }

        SecurityEvent::query()
            ->whereIn('id', $eventIds)
            ->get(['id', 'source_id'])
            ->groupBy('source_id')
            ->each(function ($events): void {
                $ids = $events->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

                /** @var list<int> $ids */
                PushEventStatesJob::dispatch($ids);
            });

        $this->selectedEventIds = [];

        Notification::make()->title('Selected alerts queued for sync')->success()->send();
    }
}
