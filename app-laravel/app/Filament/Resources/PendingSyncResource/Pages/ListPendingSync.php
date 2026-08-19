<?php

namespace App\Filament\Resources\PendingSyncResource\Pages;

use App\Filament\Resources\PendingSyncResource;
use App\Models\SecurityEvent;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPendingSync extends ListRecords
{
    protected static string $resource = PendingSyncResource::class;

    /**
     * @param  Builder<SecurityEvent>  $query
     * @return Builder<SecurityEvent>
     */
    protected function applySortingToTableQuery(Builder $query): Builder
    {
        if ($this->getTableSortColumn()) {
            return parent::applySortingToTableQuery($query);
        }

        return $query->orderBy('source_id')->orderByDesc('updated_at');
    }
}
