<?php

namespace App\Filament\Resources\LocalFindingResource\Pages;

use App\Filament\Resources\LocalFindingResource;
use App\Filament\Resources\LocalFindingResource\Support\LocalFindingTableQuery;
use App\Filament\Support\PersistsListViewState;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListLocalFindings extends ListRecords
{
    use PersistsListViewState;

    protected static string $resource = LocalFindingResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->restoreOrApplyDefaultViewState();
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

    protected function viewStateId(): string
    {
        return 'local-findings:list';
    }

    /** @return array<string, mixed> */
    protected function defaultTableFilters(): array
    {
        return [
            'status' => ['values' => [EventState::Open->value]],
            'severity' => ['values' => [EventSeverity::Critical->value, EventSeverity::High->value]],
        ];
    }
}
