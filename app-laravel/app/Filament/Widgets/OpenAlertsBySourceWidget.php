<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Widgets\Support\DashboardData;
use App\Models\Enums\EventState;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class OpenAlertsBySourceWidget extends Widget
{
    protected string $view = 'filament.widgets.open-alerts-by-source';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        return Auth::user()?->can('alerts.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $rows = DashboardData::openAlertsBySourceAndWorkItemState();

        $rowsWithUrls = array_map(function (array $row): array {
            $row['source_url'] = SecurityEventResource::filteredIndexUrl([
                'source_id' => [$row['source_id']],
                'state' => [EventState::Open->value],
            ]);
            $row['linked_url'] = SecurityEventResource::filteredIndexUrl([
                'source_id' => [$row['source_id']],
                'state' => [EventState::Open->value],
                'has_work_item' => ['1'],
            ]);
            $row['unlinked_url'] = SecurityEventResource::filteredIndexUrl([
                'source_id' => [$row['source_id']],
                'state' => [EventState::Open->value],
                'has_work_item' => ['0'],
            ]);

            return $row;
        }, $rows);

        return ['rows' => $rowsWithUrls];
    }
}
