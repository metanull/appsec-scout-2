<?php

namespace App\Filament\Resources\SecurityEventResource\Pages;

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Filament\Support\PersistsListViewState;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSecurityEvents extends ListRecords
{
    use PersistsListViewState;

    protected static string $resource = SecurityEventResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->restoreOrApplyDefaultViewState();
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

    protected function viewStateId(): string
    {
        return 'security-events:list';
    }

    /** @return array<string, mixed> */
    protected function defaultTableFilters(): array
    {
        return [
            'state' => ['values' => [EventState::Open->value]],
            'severity' => ['values' => [EventSeverity::Critical->value, EventSeverity::High->value]],
        ];
    }
}
