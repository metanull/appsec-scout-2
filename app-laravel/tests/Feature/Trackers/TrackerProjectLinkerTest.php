<?php

use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\TrackerProjectLink;
use App\Models\User;
use App\Trackers\Dto\WorkItemDto;
use App\Trackers\WorkItemService;
use Database\Seeders\RolePermissionSeeder;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('records tracker project link for system when creating a work item', function () {
    bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);

    $system = SoftwareSystem::factory()->create();
    $event = SecurityEvent::factory()->secret()->create([
        'software_system_id' => $system->id,
        'container_id' => null,
    ]);

    app(WorkItemService::class)->createForEvents(
        eventIds: [$event->id],
        userId: $user->id,
        trackerId: 'fake-tracker',
        projectKey: 'APP',
        itemType: 'Bug',
    );

    expect(TrackerProjectLink::query()
        ->where('owner_type', SoftwareSystem::class)
        ->where('owner_id', $system->id)
        ->where('tracker_id', 'fake-tracker')
        ->where('project_key', 'APP')
        ->exists())->toBeTrue();
});

it('records tracker project link for container when creating a work item', function () {
    bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);

    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->create(['software_system_id' => $system->id]);
    $event = SecurityEvent::factory()->secret()->create([
        'software_system_id' => $system->id,
        'container_id' => $container->id,
    ]);

    app(WorkItemService::class)->createForEvents(
        eventIds: [$event->id],
        userId: $user->id,
        trackerId: 'fake-tracker',
        projectKey: 'APP',
        itemType: 'Bug',
    );

    expect(TrackerProjectLink::query()
        ->where('owner_type', SecurityContainer::class)
        ->where('owner_id', $container->id)
        ->where('tracker_id', 'fake-tracker')
        ->where('project_key', 'APP')
        ->exists())->toBeTrue();
});

it('records tracker project links for all systems and containers when creating grouped work item', function () {
    bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);

    $system1 = SoftwareSystem::factory()->create();
    $system2 = SoftwareSystem::factory()->create();
    $container1 = SecurityContainer::factory()->create(['software_system_id' => $system1->id]);

    $events = [
        SecurityEvent::factory()->secret()->create(['software_system_id' => $system1->id, 'container_id' => $container1->id]),
        SecurityEvent::factory()->secret()->create(['software_system_id' => $system2->id, 'container_id' => null]),
    ];

    app(WorkItemService::class)->createForEvents(
        eventIds: array_map(fn ($e) => $e->id, $events),
        userId: $user->id,
        trackerId: 'fake-tracker',
        projectKey: 'APP',
        itemType: 'Bug',
    );

    expect(TrackerProjectLink::query()->count())->toBe(3);
});

it('does not duplicate tracker project links on repeated calls', function () {
    bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);

    $system = SoftwareSystem::factory()->create();
    $event = SecurityEvent::factory()->secret()->create(['software_system_id' => $system->id, 'container_id' => null]);

    app(WorkItemService::class)->createForEvents([$event->id], $user->id, 'fake-tracker', 'APP', 'Bug');

    $tracker2 = bindFakeWorkItemTracker(new FakeTracker);
    $tracker2->withExistingWorkItem(new WorkItemDto('APP#2', 'APP', 'Item 2', 'Open'));

    app(WorkItemService::class)->linkExisting([$event->id], $user->id, 'fake-tracker', 'APP#2', 'APP');

    expect(TrackerProjectLink::query()
        ->where('owner_type', SoftwareSystem::class)
        ->where('owner_id', $system->id)
        ->where('tracker_id', 'fake-tracker')
        ->where('project_key', 'APP')
        ->count())->toBe(1);
});

it('records tracker project link when linking an existing work item', function () {
    $tracker = bindFakeWorkItemTracker((new FakeTracker)->withExistingWorkItem(
        new WorkItemDto('APP#7', 'APP', 'Existing', 'Open')
    ));
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);

    $system = SoftwareSystem::factory()->create();
    $event = SecurityEvent::factory()->secret()->create(['software_system_id' => $system->id, 'container_id' => null]);

    app(WorkItemService::class)->linkExisting([$event->id], $user->id, 'fake-tracker', 'APP#7', 'APP');

    expect(TrackerProjectLink::query()
        ->where('owner_type', SoftwareSystem::class)
        ->where('owner_id', $system->id)
        ->where('tracker_id', 'fake-tracker')
        ->where('project_key', 'APP')
        ->exists())->toBeTrue();
});

it('creates only a system level link for events without containers', function () {
    bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);

    $system = SoftwareSystem::factory()->create();
    $event = SecurityEvent::factory()->secret()->create([
        'software_system_id' => $system->id,
        'container_id' => null,
    ]);

    app(WorkItemService::class)->createForEvents([$event->id], $user->id, 'fake-tracker', 'APP', 'Bug');

    expect(TrackerProjectLink::query()->count())->toBe(1)
        ->and(TrackerProjectLink::query()->first()?->owner_type)->toBe(SoftwareSystem::class);
});
