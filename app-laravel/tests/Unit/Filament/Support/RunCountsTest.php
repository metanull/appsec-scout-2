<?php

use App\Filament\Support\RunCounts;

it('formats counts as completed / considered with a failed tally', function () {
    expect(RunCounts::format(['repositories_considered' => 3, 'repositories_completed' => 2, 'repositories_failed' => 1]))
        ->toBe('2 / 3 · 1 failed');
});

it('formats counts as zeroes when the counts array is empty', function () {
    expect(RunCounts::format([]))->toBe('0 / 0 · 0 failed');
});

it('formats counts as zeroes when null', function () {
    expect(RunCounts::format(null))->toBe('0 / 0 · 0 failed');
});
