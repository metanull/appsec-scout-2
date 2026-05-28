<?php

use App\Events\SyncRunFinished;
use App\Filament\Widgets\Support\DashboardData;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityEvent;
use App\Models\SyncRun;
use App\Models\WorkItemLink;
use Illuminate\Support\Carbon;

beforeEach(function () {
    DashboardData::flushCache();
});

afterEach(function () {
    DashboardData::flushCache();
});

it('builds dashboard stats grouped by severity and state', function () {
    SecurityEvent::factory()->create([
        'severity' => EventSeverity::Critical,
        'state' => EventState::Open,
        'type' => EventType::Secret,
    ]);

    SecurityEvent::factory()->create([
        'severity' => EventSeverity::High,
        'state' => EventState::Resolved,
        'type' => EventType::Vulnerability,
    ]);

    $stats = DashboardData::stats();

    expect($stats['totalOpen'])->toBe(1)
        ->and($stats['severities']['critical'])->toBe(1)
        ->and($stats['severities']['high'])->toBe(1)
        ->and($stats['states']['open'])->toBe(1)
        ->and($stats['states']['resolved'])->toBe(1);
});

it('builds doughnut chart dataset from severity counts', function () {
    SecurityEvent::factory()->create(['severity' => EventSeverity::Critical]);
    SecurityEvent::factory()->create(['severity' => EventSeverity::Medium]);
    SecurityEvent::factory()->create(['severity' => EventSeverity::Medium]);

    $chart = DashboardData::severityChart();

    expect($chart['labels'])->toBe(['Critical', 'High', 'Medium', 'Low', 'Informational'])
        ->and($chart['datasets'])->toHaveCount(1)
        ->and($chart['datasets'][0]['data'])->toBe([1, 0, 2, 0, 0]);
});

it('returns the latest ten sync runs in descending order', function () {
    DashboardData::flushCache();
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

    expect($runs)->toHaveCount(10)
        ->and($runs->first()->counts_json['events_created'])->toBe(12)
        ->and($runs->last()->counts_json['events_created'])->toBe(3);

    Carbon::setTestNow();
});

it('flushes dashboard cache when sync run finished event is dispatched', function () {
    SecurityEvent::factory()->create(['state' => EventState::Open]);

    $before = DashboardData::stats();

    SecurityEvent::factory()->create(['state' => EventState::Open]);

    $cached = DashboardData::stats();

    expect($before['totalOpen'])->toBe(1)
        ->and($cached['totalOpen'])->toBe(1);

    $run = SyncRun::query()->create([
        'source_id' => 'azdo',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['events_created' => 0, 'events_updated' => 1],
        'error_message' => null,
    ]);

    event(new SyncRunFinished($run));

    $after = DashboardData::stats();

    expect($after['totalOpen'])->toBe(2);
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

it('formats counts with known keys compactly', function () {
    expect(DashboardData::formatCounts([
        'systems_created' => 1,
        'systems_updated' => 0,
        'containers_created' => 0,
        'containers_updated' => 2,
        'events_created' => 15,
        'events_updated' => 4,
        'events_pushed' => 15,
        'events_failed' => 1,
    ]))->toBe('sys +1/~0, ctr +0/~2, evt +15/~4, pushed 15, failed 1');
});

it('formats empty counts array as zero changes', function () {
    expect(DashboardData::formatCounts([]))->toBe('0 changes');
});

it('formats null counts as no counts recorded', function () {
    expect(DashboardData::formatCounts(null))->toBe('No counts recorded');
});

it('formats counts with only events changed', function () {
    expect(DashboardData::formatCounts([
        'events_created' => 3,
        'events_updated' => 0,
    ]))->toBe('evt +3/~0');
});

it('groups open alerts by source and work item linkage', function () {
    $eventA = SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'state' => EventState::Open,
    ]);

    SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'state' => EventState::Open,
    ]);

    SecurityEvent::factory()->create([
        'source_id' => 'detectify',
        'state' => EventState::Open,
    ]);

    // Resolved events should NOT be counted
    SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'state' => EventState::Resolved,
    ]);

    WorkItemLink::query()->create([
        'event_id' => $eventA->id,
        'tracker_id' => 'jira',
        'work_item_id' => 'WI-99',
        'work_item_title' => 'Fix it',
        'work_item_state' => 'Open',
        'work_item_url' => null,
        'created_by_user_id' => null,
        'synced_at' => now(),
    ]);

    $groups = DashboardData::openAlertsBySourceAndWorkItemState();

    $azdo = collect($groups)->firstWhere('source_id', 'azdo');
    $detectify = collect($groups)->firstWhere('source_id', 'detectify');

    expect($azdo)->not->toBeNull()
        ->and($azdo['linked'])->toBe(1)
        ->and($azdo['unlinked'])->toBe(1)
        ->and($detectify)->not->toBeNull()
        ->and($detectify['linked'])->toBe(0)
        ->and($detectify['unlinked'])->toBe(1);
});

