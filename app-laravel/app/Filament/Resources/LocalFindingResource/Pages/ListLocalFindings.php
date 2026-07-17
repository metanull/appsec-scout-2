<?php

namespace App\Filament\Resources\LocalFindingResource\Pages;

use App\Filament\Resources\LocalFindingResource;
use App\Filament\Resources\LocalFindingResource\Support\LocalFindingTableQuery;
use App\Models\LocalFinding;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListLocalFindings extends ListRecords
{
    protected static string $resource = LocalFindingResource::class;

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
}
