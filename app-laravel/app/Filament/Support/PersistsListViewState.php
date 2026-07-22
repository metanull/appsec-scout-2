<?php

namespace App\Filament\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Persists a list page's table filters, search, and sort per user, so the view
 * survives navigation. On the very first visit (no saved state) the page falls
 * back to {@see self::defaultTableFilters()}; once the user changes — or clears
 * — the filters, that exact state (including an intentionally empty one) is
 * remembered and restored, rather than being re-defaulted.
 *
 * A deep link that carries table state in the query string always wins over the
 * saved state and is treated as transient (visiting it never overwrites what the
 * user previously saved).
 *
 * Used only by list pages that extend {@see \Filament\Resources\Pages\ListRecords},
 * so the table state properties it reads resolve from that class hierarchy.
 */
trait PersistsListViewState
{
    /**
     * Signature of the table state that is already reflected in the store, used
     * to avoid redundant writes. Public so Livewire carries it across requests.
     */
    public ?string $persistedViewSignature = null;

    /** Stable identifier for this list's saved view state. */
    abstract protected function viewStateId(): string;

    /**
     * Table filter state applied on the first visit before the user has set or
     * cleared any filter.
     *
     * @return array<string, mixed>
     */
    abstract protected function defaultTableFilters(): array;

    /**
     * Restore the saved view state, or apply the baseline defaults on a first
     * visit. Must run after the parent mount so it wins over Filament's own
     * initialisation.
     */
    protected function restoreOrApplyDefaultViewState(): void
    {
        if ($this->requestCarriesTableState()) {
            $this->persistedViewSignature = $this->currentViewStateSignature();

            return;
        }

        $userId = Auth::id();

        if (! is_int($userId)) {
            $this->persistedViewSignature = $this->currentViewStateSignature();

            return;
        }

        $state = app(UserViewStateStore::class)->load($userId, $this->viewStateId());

        if ($state === []) {
            // First visit: no saved state yet, so apply the baseline defaults.
            // These are intentionally not persisted, so they keep applying until
            // the user makes an explicit choice (including clearing everything).
            $this->tableFilters = $this->defaultTableFilters();
        } else {
            if (isset($state['filters']) && is_array($state['filters'])) {
                $this->tableFilters = $state['filters'];
            }

            if (array_key_exists('search', $state)) {
                $this->tableSearch = is_string($state['search']) ? $state['search'] : '';
            }

            if (array_key_exists('sort', $state) && (is_string($state['sort']) || $state['sort'] === null)) {
                $this->tableSort = $state['sort'];
            }
        }

        $this->persistedViewSignature = $this->currentViewStateSignature();
    }

    /**
     * Livewire dehydrate hook (auto-invoked because this trait is used): persist
     * the current table state at the end of every request in which it changed.
     * Catching it here — rather than only on the filter/search/sort update hooks
     * — means resets and per-filter removals are remembered too.
     */
    public function dehydratePersistsListViewState(): void
    {
        $userId = Auth::id();

        if (! is_int($userId)) {
            return;
        }

        $signature = $this->currentViewStateSignature();

        if ($signature === $this->persistedViewSignature) {
            return;
        }

        app(UserViewStateStore::class)->save($userId, $this->viewStateId(), [
            'filters' => $this->normalizedTableFilters(),
            'search' => $this->tableSearch,
            'sort' => $this->tableSort,
        ]);

        $this->persistedViewSignature = $signature;
    }

    /**
     * A deep link (e.g. a dashboard breakdown row) carries the user's most
     * recent intent in the query string; when present it must win over any
     * persisted view state rather than be silently overwritten on mount.
     */
    protected function requestCarriesTableState(): bool
    {
        $request = request();

        foreach (['tableFilters', 'tableSearch', 'tableSort', 'tableSortColumn'] as $key) {
            $value = $request->query($key);

            if (is_array($value) ? $value !== [] : ($value !== null && $value !== '')) {
                return true;
            }
        }

        return false;
    }

    private function currentViewStateSignature(): string
    {
        return json_encode([
            'filters' => $this->normalizedTableFilters(),
            'search' => $this->tableSearch,
            'sort' => $this->tableSort,
        ]) ?: '';
    }

    /** @return array<string, mixed> */
    private function normalizedTableFilters(): array
    {
        return is_array($this->tableFilters) ? $this->tableFilters : [];
    }
}
