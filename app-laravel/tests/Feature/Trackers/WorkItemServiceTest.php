<?php

use App\Audit\AuditLog;
use App\Models\ErrorLog;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WorkItemLink;
use App\Trackers\Dto\CreatedWorkItem;
use App\Trackers\Dto\WorkItemDto;
use App\Trackers\WorkItemService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('creates a single work item and links one event', function () {
    $tracker = bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    $event = SecurityEvent::factory()->secret()->create([
        'source_id' => 'azdo',
        'title' => 'Hardcoded PAT in config',
        'description' => "Short desc\n\nThis is a cross-site scripting vulnerability.",
        'url' => 'https://example.test/alerts/secret-101',
    ]);

    $created = app(WorkItemService::class)->createForEvents(
        eventIds: [$event->id],
        userId: $user->id,
        trackerId: 'fake-tracker',
        projectKey: 'APP',
        itemType: 'Bug',
        labels: ['security', 'appsec-scout', 'github'],
        priority: 'High',
    );

    $link = WorkItemLink::query()->first();

    expect($created)->toBeInstanceOf(CreatedWorkItem::class)
        ->and($created->commentError)->toBeNull()
        ->and($created->workItem->id)->toBe($link?->work_item_id)
        ->and($created->workItem->url)->toBe($link?->work_item_url)
        ->and($tracker->createCalls)->toBe(1)
        ->and($tracker->latestCreateWorkItemRequest)->not->toBeNull()
        ->and($tracker->latestCreateWorkItemRequest?->description)->toContain('[View alert](https://example.test/alerts/secret-101)')
        ->and($tracker->latestCreateWorkItemRequest?->description)->toContain('This is a cross-site scripting vulnerability.')
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
    $events = collect([
        SecurityEvent::factory()->create([
            'source_id' => 'azdo',
            'title' => 'Grouped alert 1',
            'description' => "Short desc\n\nThis is a cross-site scripting vulnerability.",
            'url' => 'https://example.test/alerts/grouped-1',
        ]),
        SecurityEvent::factory()->create([
            'source_id' => 'azdo',
            'title' => 'Grouped alert 2',
            'description' => "Short desc\n\nThis is a cross-site scripting vulnerability.",
            'url' => 'https://example.test/alerts/grouped-2',
        ]),
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
        ->and($tracker->latestCreateWorkItemRequest)->not->toBeNull()
        ->and($tracker->latestCreateWorkItemRequest?->description)->toContain('[source alert](https://example.test/alerts/grouped-1)')
        ->and($tracker->latestCreateWorkItemRequest?->description)->toContain('This is a cross-site scripting vulnerability.')
        ->and($links)->toHaveCount(2)
        ->and($workItemIds)->toHaveCount(1)
        ->and($audit?->payload_json['grouped'])->toBeTrue()
        ->and($audit?->payload_json['event_ids'])->toHaveCount(2);
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

it('posts the operator comment on the new work item and audits it', function () {
    $tracker = bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $event = SecurityEvent::factory()->create();

    $created = app(WorkItemService::class)->createForEvents(
        eventIds: [$event->id],
        userId: $user->id,
        trackerId: 'fake-tracker',
        projectKey: 'APP',
        itemType: 'Bug',
        comment: "  ## Triage\n\nConfirmed true positive.  ",
    );

    $audit = AuditLog::query()->where('action', 'work_item_comment_added')->sole();

    expect($created->commentError)->toBeNull()
        ->and($tracker->comments)->toBe([['key' => $created->workItem->id, 'markdown' => "## Triage\n\nConfirmed true positive."]])
        ->and($audit->subject_id)->toBe((string) $event->id)
        ->and($audit->payload_json)->toMatchArray([
            'status' => 'success',
            'tracker_id' => 'fake-tracker',
            'work_item_id' => $created->workItem->id,
            'comment' => "## Triage\n\nConfirmed true positive.",
        ]);
});

it('posts no comment when the comment is blank', function () {
    $tracker = bindFakeWorkItemTracker(new FakeTracker);
    $event = SecurityEvent::factory()->create();

    app(WorkItemService::class)->createForEvents([$event->id], User::factory()->create()->id, 'fake-tracker', 'APP', 'Bug', comment: "  \n ");

    expect($tracker->createCalls)->toBe(1)
        ->and($tracker->comments)->toBe([])
        ->and(AuditLog::query()->where('action', 'work_item_comment_added')->exists())->toBeFalse();
});

it('refuses a comment longer than the tracker accepts before creating anything', function () {
    $tracker = bindFakeWorkItemTracker(new FakeTracker);
    $event = SecurityEvent::factory()->create();

    expect(fn () => app(WorkItemService::class)->createForEvents(
        [$event->id], User::factory()->create()->id, 'fake-tracker', 'APP', 'Bug', comment: str_repeat('é', 32768),
    ))->toThrow(ValidationException::class, 'The comment is 32768 characters long; Fake Tracker accepts at most 32767. Nothing was created.');

    expect($tracker->createCalls)->toBe(0)
        ->and(WorkItemLink::query()->count())->toBe(0);
});

it('keeps the created and linked work item when its comment cannot be posted', function () {
    $tracker = bindFakeWorkItemTracker((new FakeTracker)->withCommentFailure('403 Forbidden'));
    $event = SecurityEvent::factory()->create();

    $created = app(WorkItemService::class)->createForEvents(
        [$event->id], User::factory()->create()->id, 'fake-tracker', 'APP', 'Bug', comment: 'Confirmed true positive.',
    );

    $error = ErrorLog::query()->where('channel', 'work-items')->sole();

    expect($created->commentError)->toBe('403 Forbidden')
        ->and($tracker->createCalls)->toBe(1)
        ->and(WorkItemLink::query()->where('event_id', $event->id)->value('work_item_id'))->toBe($created->workItem->id)
        ->and($error->message)->toBe("Work item {$created->workItem->id} was created, but its comment could not be posted: 403 Forbidden")
        ->and(AuditLog::query()->where('action', 'work_item_comment_added')->sole()->payload_json)
        ->toMatchArray(['status' => 'failure', 'error' => '403 Forbidden']);
});
