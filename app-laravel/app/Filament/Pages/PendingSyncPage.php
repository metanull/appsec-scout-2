<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Sync\PendingSyncQuery;
use App\Sync\PushEventStatesJob;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class PendingSyncPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-on-square-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Sync';

    protected static ?string $navigationLabel = 'Pending Sync';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'sync/pending';

    protected string $view = 'filament.pages.pending-sync-page';

    /** @var list<int> */
    public array $selectedEventIds = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

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

        PushEventStatesJob::dispatch($eventIds);

        $this->selectedEventIds = [];

        Notification::make()->title('Selected alerts queued for sync')->success()->send();
    }
}
