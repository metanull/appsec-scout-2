<?php

use App\Events\SyncRunFinished;
use App\Filament\Widgets\Support\DashboardData;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityEvent;
use App\Models\SyncRun;

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
