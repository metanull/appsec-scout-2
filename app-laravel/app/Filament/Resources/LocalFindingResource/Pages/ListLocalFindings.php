<?php

namespace App\Filament\Resources\LocalFindingResource\Pages;

use App\Filament\Resources\LocalFindingResource;
use App\Filament\Resources\LocalFindingResource\Support\LocalFindingTableQuery;
use App\Filament\Support\UserViewStateStore;
use App\Models\LocalFinding;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListLocalFindings extends ListRecords
{
    protected static string $resource = LocalFindingResource::class;

    private const VIEW_ID = 'local-findings:list';

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
     * @param  Builder<LocalFinding>  $query
     * @return Builder<LocalFinding>
     */
    protected function applySortingToTableQuery(Builder $query): Builder
    {
        if ($this->getTableSortColumn()) {
            return parent::applySortingToTableQuery($query);
        }

        return $query
            ->orderByRaw(LocalFindingTableQuery::effectiveSeverityRankSql() . ' DESC')
            ->orderByDesc('last_seen_at');
    }

    /**
     * @param  Builder<LocalFinding>  $query
     * @return Builder<LocalFinding>
     */
    protected function applySearchToTableQuery(Builder $query): Builder
    {
        return LocalFindingTableQuery::applySearch($query, $this->tableSearch);
    }

    private function restoreUserViewState(): void
    {
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
