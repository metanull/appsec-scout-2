<?php

namespace App\Filament\Widgets\Support;

use Illuminate\Support\Collection;

/**
 * Builds the "By Work Item status" breakdown rows shared by the Alerts and
 * Local Findings data classes from a grouped `COUNT(DISTINCT owner_id)` result:
 * one row per distinct non-null status, an Unknown bucket for null-state links,
 * and a No work item bucket for records with no link.
 */
final class WorkItemStatusBreakdown
{
    public const UNKNOWN_KEY = '__none__';

    public const NO_WORK_ITEM_KEY = '__no_work_item__';

    /**
     * @param  Collection<int, \stdClass>  $statusRows  rows exposing `work_item_state` and `total`
     * @return list<array{key: string, label: string, count: int, color: string|array<array-key, string>}>
     */
    public static function rows(Collection $statusRows, int $noWorkItem): array
    {
        $rows = [];
        $unknown = 0;

        foreach ($statusRows as $row) {
            $state = $row->work_item_state;
            $count = (int) $row->total;

            if ($state === null) {
                $unknown += $count;

                continue;
            }

            $rows[] = [
                'key' => (string) $state,
                'label' => (string) $state,
                'count' => $count,
                'color' => BreakdownColor::neutral((string) $state),
            ];
        }

        if ($unknown > 0) {
            $rows[] = ['key' => self::UNKNOWN_KEY, 'label' => 'Unknown', 'count' => $unknown, 'color' => 'gray'];
        }

        $rows[] = ['key' => self::NO_WORK_ITEM_KEY, 'label' => 'No work item', 'count' => $noWorkItem, 'color' => 'warning'];

        return $rows;
    }
}
