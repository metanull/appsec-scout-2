<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Shared "from"/"until" date-range filter pair, used by every list that lets
 * an operator narrow results to a date range on a single timestamp column.
 */
final class DateRangeFilters
{
    /** @return array{0: Filter, 1: Filter} */
    public static function for(string $column, string $fromLabel = 'From', string $untilLabel = 'Until'): array
    {
        return [
            Filter::make("{$column}_from")
                ->label($fromLabel)
                ->form([DatePicker::make("{$column}_from")])
                ->query(fn (Builder $query, array $data) => $query->when(
                    $data["{$column}_from"] ?? null,
                    fn (Builder $q, string $v) => $q->whereDate($column, '>=', Carbon::parse($v)),
                )),
            Filter::make("{$column}_until")
                ->label($untilLabel)
                ->form([DatePicker::make("{$column}_until")])
                ->query(fn (Builder $query, array $data) => $query->when(
                    $data["{$column}_until"] ?? null,
                    fn (Builder $q, string $v) => $q->whereDate($column, '<=', Carbon::parse($v)),
                )),
        ];
    }
}
