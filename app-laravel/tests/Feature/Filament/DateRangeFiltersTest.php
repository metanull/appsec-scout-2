<?php

use App\Filament\Support\DateRangeFilters;
use App\Models\ErrorLog;

it('builds a from/until filter pair that narrows a query by date', function () {
    ErrorLog::query()->create(['occurred_at' => '2026-01-05', 'level' => 'ERROR', 'channel' => 'app', 'message' => 'early', 'trace' => '']);
    ErrorLog::query()->create(['occurred_at' => '2026-01-15', 'level' => 'ERROR', 'channel' => 'app', 'message' => 'middle', 'trace' => '']);
    ErrorLog::query()->create(['occurred_at' => '2026-01-25', 'level' => 'ERROR', 'channel' => 'app', 'message' => 'late', 'trace' => '']);

    [$from, $until] = DateRangeFilters::for('occurred_at');

    $fromResults = $from->apply(ErrorLog::query(), ['occurred_at_from' => '2026-01-10'])
        ->pluck('message')->sort()->values()->all();
    expect($fromResults)->toBe(['late', 'middle']);

    $untilResults = $until->apply(ErrorLog::query(), ['occurred_at_until' => '2026-01-10'])
        ->pluck('message')->sort()->values()->all();
    expect($untilResults)->toBe(['early']);
});

it('does not narrow the query when no date is provided', function () {
    ErrorLog::query()->create(['occurred_at' => '2026-01-05', 'level' => 'ERROR', 'channel' => 'app', 'message' => 'only', 'trace' => '']);

    [$from, $until] = DateRangeFilters::for('occurred_at');

    $count = $until->apply($from->apply(ErrorLog::query(), ['occurred_at_from' => null]), ['occurred_at_until' => null])->count();

    expect($count)->toBe(1);
});
