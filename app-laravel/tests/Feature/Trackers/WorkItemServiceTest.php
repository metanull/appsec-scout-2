<?php

use App\Audit\AuditLog;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WorkItemLink;
use App\Trackers\Dto\WorkItemDto;
use App\Trackers\Registry;
use App\Trackers\WorkItemService;
use Database\Seeders\RolePermissionSeeder;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('creates a single work item and links one event', function () {
    $tracker = bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    $event = SecurityEvent::factory()->secret()->create([
        'source_id' => 'github',
        'title' => 'Hardcoded PAT in config',
    ]);

    app(WorkItemService::class)->createForEvents(
        eventIds: [$event->id],
        userId: $user->id,
        trackerId: 'fake-tracker',
        projectKey: 'APP',
        itemType: 'Bug',
        labels: ['security', 'appsec-scout', 'github'],
        priority: 'High',
    );

    $link = WorkItemLink::query()->first();

    expect($tracker->createCalls)->toBe(1)
        ->and($link)->not->toBeNull()
        ->and($link?->event_id)->toBe($event->id)
        ->and($link?->tracker_id)->toBe('fake-tracker')
        ->and($link?->work_item_title)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'work_item_created')->exists())->toBeTrue();
});

it('creates one grouped work item and links all selected events', function () {
    $tracker = bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    $events = SecurityEvent::factory()->count(5)->create([
        'source_id' => 'github',
        'title' => 'Grouped alert',
    ]);

    app(WorkItemService::class)->createForEvents(
        eventIds: $events->pluck('id')->all(),
        userId: $user->id,
        trackerId: 'fake-tracker',
        projectKey: 'APP',
        itemType: 'Bug',
        labels: ['security', 'appsec-scout'],
    );

    $links = WorkItemLink::query()->orderBy('event_id')->get();
    $workItemIds = $links->pluck('work_item_id')->unique()->values()->all();
    $audit = AuditLog::query()->where('action', 'work_item_created')->latest('id')->first();

    expect($tracker->createCalls)->toBe(1)
        ->and($links)->toHaveCount(5)
        ->and($workItemIds)->toHaveCount(1)
        ->and($audit?->payload_json['grouped'])->toBeTrue()
        ->and($audit?->payload_json['event_ids'])->toHaveCount(5);
});

it('links an existing work item to selected events', function () {
    $tracker = bindFakeWorkItemTracker((new FakeTracker)->withExistingWorkItem(new WorkItemDto(
        id: 'APP#7',
        projectKey: 'APP',
        title: 'Existing tracker item',
        state: 'Open',
        url: 'https://tracker.test/APP%237',
    )));
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    $events = SecurityEvent::factory()->count(2)->create();

    app(WorkItemService::class)->linkExisting(
        eventIds: $events->pluck('id')->all(),
        userId: $user->id,
        trackerId: 'fake-tracker',
        workItemId: 'APP#7',
    );

    expect($tracker->getCalls)->toBe(1)
        ->and(WorkItemLink::count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'work_item_linked')->exists())->toBeTrue();
});

it('unlinks a work item and records an audit row', function () {
    $event = SecurityEvent::factory()->create();
    $link = WorkItemLink::query()->create([
        'event_id' => $event->id,
        'tracker_id' => 'github',
        'work_item_id' => 'octo/app#101',
        'work_item_title' => 'Linked work item',
    ]);

    app(WorkItemService::class)->unlink($link);

    expect(WorkItemLink::count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'work_item_unlinked')->exists())->toBeTrue();
});

function bindFakeWorkItemTracker(FakeTracker $tracker): FakeTracker
{
    config(['integration_settings.fake-tracker.enabled' => true]);

    app()->bind('appsec-scout.tracker.fake', fn () => $tracker);
    app()->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    app()->forgetInstance(Registry::class);

    return $tracker;
}
