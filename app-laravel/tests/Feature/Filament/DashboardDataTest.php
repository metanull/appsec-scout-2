<?php

use App\Events\SyncRunFinished;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\DashboardData;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;

it('returns the latest five sync runs in descending order', function () {
    SyncRun::query()->delete();

    Carbon::setTestNow('2026-05-22 12:00:00');

    for ($i = 1; $i <= 12; $i++) {
        SyncRun::query()->create([
            'source_id' => 'azdo',
            'started_at' => now()->subMinutes(20 - $i),
            'finished_at' => now()->subMinutes(20 - $i)->addSeconds(15),
            'status' => 'success',
            'counts_json' => ['events_created' => $i, 'events_updated' => 0],
            'error_message' => null,
        ]);
    }

    $runs = DashboardData::recentSyncRuns();

    expect($runs)->toHaveCount(5)
        ->and($runs->first()->counts_json['events_created'])->toBe(12)
        ->and($runs->last()->counts_json['events_created'])->toBe(8);

    Carbon::setTestNow();
});

it('flushes the breakdown caches when a sync run finished event is dispatched', function () {
    AlertBreakdownData::flushCache();
    LocalFindingBreakdownData::flushCache();

    SecurityEvent::factory()->create(['state' => EventState::Open]);

    $before = AlertBreakdownData::stateBreakdown();

    SecurityEvent::factory()->create(['state' => EventState::Open]);

    $cached = AlertBreakdownData::stateBreakdown();

    expect(collect($before)->firstWhere('key', 'open')['count'])->toBe(1)
        ->and(collect($cached)->firstWhere('key', 'open')['count'])->toBe(1);

    $run = SyncRun::query()->create([
        'source_id' => 'azdo',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['events_created' => 0, 'events_updated' => 1],
        'error_message' => null,
    ]);

    event(new SyncRunFinished($run));

    $after = AlertBreakdownData::stateBreakdown();

    expect(collect($after)->firstWhere('key', 'open')['count'])->toBe(2);
});

it('returns non-negative duration when finished_at is after started_at', function () {
    $run = SyncRun::query()->create([
        'source_id' => 'azdo',
        'started_at' => '2026-05-22 12:00:00',
        'finished_at' => '2026-05-22 12:01:30',
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    expect(DashboardData::durationSeconds($run))->toBe(90);
});

it('returns non-negative duration even when timestamps appear reversed', function () {
    $run = SyncRun::query()->create([
        'source_id' => 'azdo',
        'started_at' => '2026-05-22 12:01:30',
        'finished_at' => '2026-05-22 12:00:00',
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    expect(DashboardData::durationSeconds($run))->toBe(90);
});

it('returns null duration when timestamps are missing', function () {
    $run = SyncRun::query()->create([
        'source_id' => 'azdo',
        'started_at' => now(),
        'finished_at' => null,
        'status' => 'running',
        'counts_json' => [],
        'error_message' => null,
    ]);

    expect(DashboardData::durationSeconds($run))->toBeNull();
});

it('formats a successful fetch run as alerts retrieved with no warnings or errors', function () {
    $run = new SyncRun([
        'status' => 'success',
        'counts_json' => [
            'systems_created' => 1,
            'systems_updated' => 0,
            'containers_created' => 0,
            'containers_updated' => 2,
            'events_created' => 15,
            'events_updated' => 4,
        ],
    ]);

    expect(DashboardData::formatCounts($run))->toBe('19 alerts retrieved, 0 warning(s), 0 error(s)');
});

it('formats a failed fetch run with one error', function () {
    $run = new SyncRun([
        'status' => 'failure',
        'counts_json' => ['events_created' => 2, 'events_updated' => 0],
    ]);

    expect(DashboardData::formatCounts($run))->toBe('2 alerts retrieved, 0 warning(s), 1 error(s)');
});

it('formats a run with no counts recorded yet as all zero', function () {
    $run = new SyncRun(['status' => 'running', 'counts_json' => []]);

    expect(DashboardData::formatCounts($run))->toBe('0 alerts retrieved, 0 warning(s), 0 error(s)');
});

it('formats a push run using its succeeded/local-only/failed counts', function () {
    $run = new SyncRun([
        'status' => 'success',
        'counts_json' => [
            'events_succeeded' => 5,
            'events_failed' => 1,
            'events_skipped' => 0,
            'events_resolved_local_only' => 2,
        ],
    ]);

    expect(DashboardData::formatCounts($run))->toBe('5 alerts retrieved, 2 warning(s), 1 error(s)');
});
