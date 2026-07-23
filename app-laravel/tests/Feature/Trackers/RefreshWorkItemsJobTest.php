<?php

use App\Audit\AuditLog;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\WorkItemLink;
use App\Sync\SystemIntegrationRuntime;
use App\Trackers\Dto\WorkItemDto;
use App\Trackers\RefreshWorkItemsJob;
use App\Trackers\Registry;
use App\Trackers\WorkItemRefreshService;
use Tests\Fakes\FakeTracker;

it('refreshes cached tracker state and title without mutating the alert state', function () {
    $tracker = bindFakeRefreshTracker((new FakeTracker)->withExistingWorkItem(new WorkItemDto(
        id: 'APP#101',
        projectKey: 'APP',
        title: 'Tracker item refreshed',
        state: 'Closed (not planned)',
        url: 'https://tracker.test/APP%23101',
    )));

    $event = SecurityEvent::factory()->create([
        'state' => EventState::Open,
    ]);

    WorkItemLink::query()->create([
        'event_id' => $event->id,
        'tracker_id' => 'fake-tracker',
        'work_item_id' => 'APP#101',
        'work_item_title' => 'Tracker item original',
        'work_item_state' => 'Open',
        'created_at' => now(),
    ]);

    (new RefreshWorkItemsJob)->handle(app(SystemIntegrationRuntime::class), app(WorkItemRefreshService::class));

    $event->refresh();
    $link = WorkItemLink::query()->first();

    expect($tracker->getCalls)->toBe(1)
        ->and($event->state)->toBe(EventState::Open)
        ->and($link?->work_item_title)->toBe('Tracker item refreshed')
        ->and($link?->work_item_state)->toBe('Closed (not planned)')
        ->and($link?->synced_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'tracker_state_changed')->exists())->toBeTrue();
});

it('deduplicates tracker gets for grouped work item links', function () {
    $tracker = bindFakeRefreshTracker((new FakeTracker)->withExistingWorkItem(new WorkItemDto(
        id: 'APP#101',
        projectKey: 'APP',
        title: 'Tracker item refreshed',
        state: 'Resolved',
        url: 'https://tracker.test/APP%23101',
    )));

    $events = SecurityEvent::factory()->count(5)->create();

    foreach ($events as $event) {
        WorkItemLink::query()->create([
            'event_id' => $event->id,
            'tracker_id' => 'fake-tracker',
            'work_item_id' => 'APP#101',
            'work_item_title' => 'Tracker item original',
            'work_item_state' => 'Open',
            'created_at' => now(),
        ]);
    }

    (new RefreshWorkItemsJob)->handle(app(SystemIntegrationRuntime::class), app(WorkItemRefreshService::class));

    expect($tracker->getCalls)->toBe(1)
        ->and(WorkItemLink::query()->where('work_item_state', 'Resolved')->count())->toBe(5);
});

function bindFakeRefreshTracker(FakeTracker $tracker): FakeTracker
{
    config(['integration_settings.fake-tracker.enabled' => true]);

    app()->bind('appsec-scout.tracker.fake', fn () => $tracker);
    app()->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    app()->forgetInstance(Registry::class);

    return $tracker;
}
