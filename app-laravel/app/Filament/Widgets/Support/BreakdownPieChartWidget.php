<?php

namespace App\Filament\Widgets\Support;

use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

/**
 * Base for the pie half of a breakdown pair. Renders one pie slice per row from
 * the shared aggregate, using BreakdownColor so slices match the table badges.
 *
 * @phpstan-type BreakdownRow array{key: string, label: string, count: int, color: string|array<array-key, string>}
 */
abstract class BreakdownPieChartWidget extends ChartWidget
{
    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User ? $user->can('alerts.view') : false;
    }

    /**
     * @return list<BreakdownRow>
     */
    abstract protected function rows(): array;

    protected function getType(): string
    {
        return 'pie';
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{data: list<int>, backgroundColor: list<string>}>}
     */
    protected function getData(): array
    {
        $rows = $this->rows();

        return [
            'labels' => array_map(fn (array $row): string => $row['label'], $rows),
            'datasets' => [[
                'data' => array_map(fn (array $row): int => $row['count'], $rows),
                'backgroundColor' => array_map(fn (array $row): string => BreakdownColor::chart($row['color']), $rows),
            ]],
        ];
    }
}
