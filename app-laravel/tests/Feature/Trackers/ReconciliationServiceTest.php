<?php

use App\Audit\AuditLog;
use App\Models\SecurityEvent;
use App\Models\WorkItemLink;
use App\Trackers\Dto\WorkItemDto;
use App\Trackers\Reconciliation\ReconciliationService;
use Database\Seeders\RolePermissionSeeder;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('returns empty results when event has no urls', function () {
    bindFakeWorkItemTracker(new FakeTracker);

    $event = SecurityEvent::factory()->secret()->create([
        'url' => null,
        'version_control_url' => null,
        'metadata' => null,
    ]);

    $results = app(ReconciliationService::class)->reconcileEvent($event);

    expect($results)->toBe([]);
});

it('returns already-linked result when event url matches existing work item url', function () {
    $tracker = new FakeTracker;
    $workItem = new WorkItemDto(
        id: 'APP#1',
        projectKey: 'APP',
        title: 'Existing WI',
        state: 'Open',
        url: 'https://tracker.test/APP%231',
        description: null,
    );
    $tracker->withExistingWorkItem($workItem);
    bindFakeWorkItemTracker($tracker);

    $event = SecurityEvent::factory()->secret()->create([
        'url' => 'https://tracker.test/APP%231',
    ]);

    WorkItemLink::query()->create([
        'event_id' => SecurityEvent::factory()->secret()->create()->id,
        'tracker_id' => 'fake-tracker',
        'work_item_id' => 'APP#1',
        'work_item_url' => 'https://tracker.test/APP%231',
        'work_item_title' => 'Existing WI',
        'work_item_state' => 'Open',
        'created_by_user_id' => null,
        'created_at' => now(),
        'synced_at' => now(),
    ]);

    // First reconcile event - should link it
    $results = app(ReconciliationService::class)->reconcileEvent($event);

    expect($results)->toHaveCount(1)
        ->and($results[0]->linkedEventIds)->toContain($event->id)
        ->and($results[0]->alreadyLinked)->toBeFalse();
});

it('returns already-linked result when link already exists', function () {
    $tracker = new FakeTracker;
    $workItem = new WorkItemDto(
        id: 'APP#1',
        projectKey: 'APP',
        title: 'Existing WI',
        state: 'Open',
        url: 'https://tracker.test/APP%231',
    );
    $tracker->withExistingWorkItem($workItem);
    bindFakeWorkItemTracker($tracker);

    $event = SecurityEvent::factory()->secret()->create([
        'url' => 'https://tracker.test/APP%231',
    ]);

    WorkItemLink::query()->create([
        'event_id' => $event->id,
        'tracker_id' => 'fake-tracker',
        'work_item_id' => 'APP#1',
        'work_item_url' => 'https://tracker.test/APP%231',
        'work_item_title' => 'Existing WI',
        'work_item_state' => 'Open',
        'created_by_user_id' => null,
        'created_at' => now(),
        'synced_at' => now(),
    ]);

    WorkItemLink::query()->create([
        'event_id' => SecurityEvent::factory()->secret()->create()->id,
        'tracker_id' => 'fake-tracker',
        'work_item_id' => 'APP#1',
        'work_item_url' => 'https://tracker.test/APP%231',
        'work_item_title' => 'Existing WI',
        'work_item_state' => 'Open',
        'created_by_user_id' => null,
        'created_at' => now(),
        'synced_at' => now(),
    ]);

    $results = app(ReconciliationService::class)->reconcileEvent($event);

    expect($results)->toHaveCount(1)
        ->and($results[0]->alreadyLinked)->toBeTrue();
});

it('creates a work item link when reconciliation finds a url match', function () {
    $tracker = new FakeTracker;
    $workItem = new WorkItemDto(
        id: 'APP#5',
        projectKey: 'APP',
        title: 'Work item 5',
        state: 'Open',
        url: 'https://tracker.test/APP%235',
    );
    $tracker->withExistingWorkItem($workItem);
    bindFakeWorkItemTracker($tracker);

    $sibling = SecurityEvent::factory()->secret()->create();
    $event = SecurityEvent::factory()->secret()->create([
        'url' => 'https://tracker.test/APP%235',
    ]);

    // Another event is already linked to APP#5
    WorkItemLink::query()->create([
        'event_id' => $sibling->id,
        'tracker_id' => 'fake-tracker',
        'work_item_id' => 'APP#5',
        'work_item_url' => 'https://tracker.test/APP%235',
        'work_item_title' => 'Work item 5',
        'work_item_state' => 'Open',
        'created_by_user_id' => null,
        'created_at' => now(),
        'synced_at' => now(),
    ]);

    app(ReconciliationService::class)->reconcileEvent($event);

    expect(WorkItemLink::query()
        ->where('event_id', $event->id)
        ->where('tracker_id', 'fake-tracker')
        ->where('work_item_id', 'APP#5')
        ->exists())->toBeTrue();

    expect(AuditLog::query()->where('action', 'work_item_linked')->exists())->toBeTrue();
});

it('reconcileAll processes all events', function () {
    $tracker = new FakeTracker;
    $workItem = new WorkItemDto(
        id: 'APP#10',
        projectKey: 'APP',
        title: 'WI 10',
        state: 'Open',
        url: 'https://tracker.test/APP%2310',
    );
    $tracker->withExistingWorkItem($workItem);
    bindFakeWorkItemTracker($tracker);

    $sibling = SecurityEvent::factory()->secret()->create();
    WorkItemLink::query()->create([
        'event_id' => $sibling->id,
        'tracker_id' => 'fake-tracker',
        'work_item_id' => 'APP#10',
        'work_item_url' => 'https://tracker.test/APP%2310',
        'work_item_title' => 'WI 10',
        'work_item_state' => 'Open',
        'created_by_user_id' => null,
        'created_at' => now(),
        'synced_at' => now(),
    ]);

    $matchingEvent = SecurityEvent::factory()->secret()->create([
        'url' => 'https://tracker.test/APP%2310',
    ]);

    SecurityEvent::factory()->secret()->create(['url' => null]);

    $results = app(ReconciliationService::class)->reconcileAll();

    $new = collect($results)->filter(fn ($r) => ! $r->alreadyLinked);

    expect($new)->toHaveCount(1)
        ->and($new->first()->linkedEventIds)->toContain($matchingEvent->id);
});
