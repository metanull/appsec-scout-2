<?php

namespace App\Filament\Resources\SecurityEventResource\Pages;

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Filament\Support\UserViewStateStore;
use App\Models\SecurityEvent;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListSecurityEvents extends ListRecords
{
    protected static string $resource = SecurityEventResource::class;

    private const VIEW_ID = 'security-events:list';

    public function mount(): void
    {
        parent::mount();
        $this->restoreUserViewState();
    }

    public function updatedTableFilters(): void
    {
        parent::updatedTableFilters();
        $this->persistUserViewState();
    }

    public function updatedTableSearch(): void
    {
        parent::updatedTableSearch();
        $this->persistUserViewState();
    }

    public function updatedTableSort(): void
    {
        parent::updatedTableSort();
        $this->persistUserViewState();
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @return Builder<SecurityEvent>
     */
    protected function applySortingToTableQuery(Builder $query): Builder
    {
        if ($this->getTableSortColumn()) {
            return parent::applySortingToTableQuery($query);
        }

        return $query
            ->orderByRaw("CASE severity WHEN 'critical' THEN 5 WHEN 'high' THEN 4 WHEN 'medium' THEN 3 WHEN 'low' THEN 2 WHEN 'informational' THEN 1 ELSE 0 END DESC")
            ->orderByDesc('last_seen_at');
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @return Builder<SecurityEvent>
     */
    protected function applySearchToTableQuery(Builder $query): Builder
    {
        return SecurityEventTableQuery::applySearch($query, $this->tableSearch);
    }

    private function restoreUserViewState(): void
    {
        if ($this->requestCarriesTableState()) {
            return;
        }

        $userId = Auth::id();

        if (! is_int($userId)) {
            return;
        }

        $state = app(UserViewStateStore::class)->load($userId, self::VIEW_ID);

        if ($state === []) {
            return;
        }

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

    /**
     * A deep link (e.g. a dashboard breakdown row) carries the user's most
     * recent intent in the query string; when present it must win over any
     * persisted view state rather than be silently overwritten on mount.
     */
    private function requestCarriesTableState(): bool
    {
        $request = request();

        foreach (['tableFilters', 'tableSearch', 'tableSort'] as $key) {
            $value = $request->query($key);

            if (is_array($value) ? $value !== [] : ($value !== null && $value !== '')) {
                return true;
            }
        }

        return false;
    }

    private function persistUserViewState(): void
    {
        $userId = Auth::id();

        if (! is_int($userId)) {
            return;
        }

        app(UserViewStateStore::class)->save($userId, self::VIEW_ID, [
            'filters' => $this->tableFilters,
            'search' => $this->tableSearch,
            'sort' => $this->tableSort,
        ]);
    }
}
