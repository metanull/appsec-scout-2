<?php

namespace App\Filament\Widgets\Support;

use Illuminate\Support\Collection;

/**
 * Builds the "Open by Software System" breakdown rows shared by the Alerts and
 * Local Findings data classes: the top systems by open count, an Others row
 * aggregating the remainder, and an Unassigned row for records with no system.
 * The scope filters are id-based and cannot express "no system", so the Others
 * and Unassigned rows are rendered without a link.
 */
final class OpenBySystemBreakdown
{
    public const UNASSIGNED_KEY = '';

    public const OTHERS_KEY = '__others__';

    private const TOP_LIMIT = 15;

    /**
     * @param  Collection<int, \stdClass>  $rows  rows exposing `system_id`, `system_name`, `total`, ordered by count desc
     * @return list<array{key: string, label: string, count: int, color: string|array<array-key, string>}>
     */
    public static function rows(Collection $rows): array
    {
        $assigned = [];
        $unassigned = 0;

        foreach ($rows as $row) {
            $count = (int) $row->total;

            if ($row->system_id === null) {
                $unassigned += $count;

                continue;
            }

            $id = (string) $row->system_id;
            $assigned[] = [
                'key' => $id,
                'label' => $row->system_name !== null ? (string) $row->system_name : "System #{$id}",
                'count' => $count,
                'color' => BreakdownColor::neutral($id),
            ];
        }

        $result = array_slice($assigned, 0, self::TOP_LIMIT);
        $remainder = array_slice($assigned, self::TOP_LIMIT);

        if ($remainder !== []) {
            $result[] = [
                'key' => self::OTHERS_KEY,
                'label' => 'Others (' . count($remainder) . ' systems)',
                'count' => array_sum(array_column($remainder, 'count')),
                'color' => 'gray',
            ];
        }

        if ($unassigned > 0) {
            $result[] = ['key' => self::UNASSIGNED_KEY, 'label' => 'Unassigned', 'count' => $unassigned, 'color' => 'gray'];
        }

        return $result;
    }
}
