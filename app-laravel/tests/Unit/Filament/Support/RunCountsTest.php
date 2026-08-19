<?php

use App\Filament\Support\RunCounts;
use App\Models\RepositoryCollectionRun;
use App\Models\StaticAnalysisRun;

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

it('computes the duration in seconds between a repository collection run\'s started_at and finished_at', function () {
    $run = new RepositoryCollectionRun;
    $run->setRawAttributes(['started_at' => '2026-01-01 00:00:00', 'finished_at' => '2026-01-01 00:01:30'], true);

    expect(RunCounts::durationSeconds($run))->toBe(90);
});

it('computes the duration in seconds for a static analysis run the same way', function () {
    $run = new StaticAnalysisRun;
    $run->setRawAttributes(['started_at' => '2026-01-01 00:00:00', 'finished_at' => '2026-01-01 00:02:00'], true);

    expect(RunCounts::durationSeconds($run))->toBe(120);
});

it('returns null for the duration when the run has not started or finished yet', function () {
    $notStarted = new RepositoryCollectionRun;
    $notStarted->setRawAttributes(['started_at' => null, 'finished_at' => null], true);

    $stillRunning = new RepositoryCollectionRun;
    $stillRunning->setRawAttributes(['started_at' => '2026-01-01 00:00:00', 'finished_at' => null], true);

    expect(RunCounts::durationSeconds($notStarted))->toBeNull()
        ->and(RunCounts::durationSeconds($stillRunning))->toBeNull();
});
