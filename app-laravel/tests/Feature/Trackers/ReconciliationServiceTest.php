<?php

use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\TrackerProjectLink;
use App\Models\User;
use App\Models\WorkItemLink;
use App\Trackers\Dto\ProjectDto;
use App\Trackers\Dto\ReconciliationCandidateDto;
use App\Trackers\Reconciliation\ReconciliationService;
use Database\Seeders\RolePermissionSeeder;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('creates link for matching reconciliation candidate in scoped project', function () {
    $tracker = (new FakeTracker)->withReconciliationCandidates('APP', new ReconciliationCandidateDto(
        trackerId: 'fake-tracker',
        workItemId: 'APP#101',
        workItemUrl: 'https://tracker.test/APP%23101',
        title: 'Security issue',
        state: 'Open',
        labels: ['security'],
        extractedUrls: ['https://tracker.test/APP%23101'],
        searchStrategy: 'project=APP',
    ));
    bindFakeWorkItemTracker($tracker);

    $event = SecurityEvent::factory()->secret()->create([
        'url' => 'https://tracker.test/APP%23101',
    ]);

    attachTrackerProject($event->softwareSystem, 'fake-tracker', 'APP');

    $operator = reconciliationOperator();

    $results = app(ReconciliationService::class)->reconcileEvent($event, $operator->id);

    expect($results)->toHaveCount(1)
        ->and($results[0]->alreadyLinked)->toBeFalse()
        ->and(WorkItemLink::query()->where('event_id', $event->id)->where('tracker_id', 'fake-tracker')->where('work_item_id', 'APP#101')->exists())->toBeTrue();
});

it('skips already existing work item links', function () {
    $tracker = (new FakeTracker)->withReconciliationCandidates('APP', new ReconciliationCandidateDto(
        trackerId: 'fake-tracker',
        workItemId: 'APP#77',
        workItemUrl: 'https://tracker.test/APP%2377',
        title: 'Existing issue',
        state: 'Open',
        labels: ['security'],
        extractedUrls: ['https://tracker.test/APP%2377'],
        searchStrategy: 'project=APP',
    ));
    bindFakeWorkItemTracker($tracker);

    $event = SecurityEvent::factory()->secret()->create([
        'url' => 'https://tracker.test/APP%2377',
    ]);

    attachTrackerProject($event->softwareSystem, 'fake-tracker', 'APP');

    WorkItemLink::query()->create([
        'event_id' => $event->id,
        'tracker_id' => 'fake-tracker',
        'work_item_id' => 'APP#77',
        'work_item_url' => 'https://tracker.test/APP%2377',
        'work_item_title' => 'Existing issue',
        'work_item_state' => 'Open',
        'created_by_user_id' => null,
        'created_at' => now(),
        'synced_at' => now(),
    ]);

    $results = app(ReconciliationService::class)->reconcileAll();

    expect($results)->toHaveCount(1)
        ->and($results[0]->alreadyLinked)->toBeTrue()
        ->and(WorkItemLink::query()->where('event_id', $event->id)->where('tracker_id', 'fake-tracker')->where('work_item_id', 'APP#77')->count())->toBe(1);
});

it('returns empty result but still searches every enabled tracker project when no tracker project links exist', function () {
    $tracker = new FakeTracker;
    bindFakeWorkItemTracker($tracker);

    SecurityEvent::factory()->secret()->create([
        'url' => 'https://tracker.test/APP%2310',
    ]);

    $results = app(ReconciliationService::class)->reconcileAll();

    expect($results)->toBe([])
        ->and($tracker->fetchProjectsCalls)->toBe(1)
        ->and($tracker->reconciliationCalls)->toBe(0);
});

it('searches an enabled tracker project that has no existing TrackerProjectLink and can produce a match', function () {
    $tracker = (new FakeTracker)
        ->withProjects(new ProjectDto(key: 'UNLINKED', name: 'Unlinked Project'))
        ->withReconciliationCandidates('UNLINKED', new ReconciliationCandidateDto(
            trackerId: 'fake-tracker',
            workItemId: 'UNLINKED#1',
            workItemUrl: 'https://tracker.test/UNLINKED%231',
            title: 'Discovered issue',
            state: 'Open',
            labels: ['security'],
            extractedUrls: ['https://tracker.test/UNLINKED%231'],
            searchStrategy: 'project=UNLINKED',
        ));
    bindFakeWorkItemTracker($tracker);

    $event = SecurityEvent::factory()->secret()->create([
        'url' => 'https://tracker.test/UNLINKED%231',
    ]);

    $results = app(ReconciliationService::class)->reconcileAll();

    expect(collect($results)->firstWhere('alreadyLinked', false))->not->toBeNull()
        ->and(WorkItemLink::query()->where('event_id', $event->id)->where('work_item_id', 'UNLINKED#1')->exists())->toBeTrue();
});

it('continues reconciliation when fetchProjects throws for an enabled tracker', function () {
    $tracker = (new FakeTracker)
        ->withFetchProjectsFailure()
        ->withReconciliationCandidates('APP', new ReconciliationCandidateDto(
            trackerId: 'fake-tracker',
            workItemId: 'APP#5',
            workItemUrl: 'https://tracker.test/APP%235',
            title: 'Still reachable via existing link',
            state: 'Open',
            labels: ['security'],
            extractedUrls: ['https://tracker.test/APP%235'],
            searchStrategy: 'project=APP',
        ));
    bindFakeWorkItemTracker($tracker);

    $event = SecurityEvent::factory()->secret()->create([
        'url' => 'https://tracker.test/APP%235',
    ]);

    attachTrackerProject($event->softwareSystem, 'fake-tracker', 'APP');

    $results = app(ReconciliationService::class)->reconcileAll();

    expect($tracker->fetchProjectsCalls)->toBe(1)
        ->and(collect($results)->firstWhere('alreadyLinked', false))->not->toBeNull()
        ->and(WorkItemLink::query()->where('event_id', $event->id)->where('work_item_id', 'APP#5')->exists())->toBeTrue();
});

it('continues reconciliation when one project search throws', function () {
    $tracker = (new FakeTracker)
        ->withReconciliationFailure('BROKEN')
        ->withReconciliationCandidates('OK', new ReconciliationCandidateDto(
            trackerId: 'fake-tracker',
            workItemId: 'OK#1',
            workItemUrl: 'https://tracker.test/OK%231',
            title: 'Recoverable issue',
            state: 'Open',
            labels: ['security'],
            extractedUrls: ['https://tracker.test/OK%231'],
            searchStrategy: 'project=OK',
        ));
    bindFakeWorkItemTracker($tracker);

    $event = SecurityEvent::factory()->secret()->create([
        'url' => 'https://tracker.test/OK%231',
    ]);

    attachTrackerProject($event->softwareSystem, 'fake-tracker', 'BROKEN');
    attachTrackerProject($event->softwareSystem, 'fake-tracker', 'OK');

    $results = app(ReconciliationService::class)->reconcileAll();

    expect(collect($results)->firstWhere('alreadyLinked', false))->not->toBeNull()
        ->and(WorkItemLink::query()->where('event_id', $event->id)->where('work_item_id', 'OK#1')->exists())->toBeTrue();
});

it('creates links for multiple events matching one work item', function () {
    $sharedUrl = 'https://tracker.test/APP%2350';

    $tracker = (new FakeTracker)->withReconciliationCandidates('APP', new ReconciliationCandidateDto(
        trackerId: 'fake-tracker',
        workItemId: 'APP#50',
        workItemUrl: $sharedUrl,
        title: 'Shared issue',
        state: 'Open',
        labels: ['security'],
        extractedUrls: [$sharedUrl],
        searchStrategy: 'project=APP',
    ));
    bindFakeWorkItemTracker($tracker);

    $first = SecurityEvent::factory()->secret()->create(['url' => $sharedUrl]);
    $second = SecurityEvent::factory()->secret()->create(['url' => $sharedUrl]);

    attachTrackerProject($first->softwareSystem, 'fake-tracker', 'APP');
    attachTrackerProject($second->softwareSystem, 'fake-tracker', 'APP');

    app(ReconciliationService::class)->reconcileAll();

    expect(WorkItemLink::query()->where('work_item_id', 'APP#50')->pluck('event_id')->all())
        ->toContain($first->id, $second->id);
});

it('matches by prefix when work item links to repository root', function () {
    $repoRoot = 'https://dev.azure.com/acme/proj/_git/repo';

    $tracker = (new FakeTracker)->withReconciliationCandidates('APP', new ReconciliationCandidateDto(
        trackerId: 'fake-tracker',
        workItemId: 'APP#90',
        workItemUrl: 'https://tracker.test/APP%2390',
        title: 'Repository fix',
        state: 'Open',
        labels: ['security'],
        extractedUrls: [$repoRoot],
        searchStrategy: 'project=APP',
    ));
    bindFakeWorkItemTracker($tracker);

    $event = SecurityEvent::factory()->secret()->create([
        'url' => $repoRoot . '/alerts/123',
    ]);

    attachTrackerProject($event->softwareSystem, 'fake-tracker', 'APP');

    app(ReconciliationService::class)->reconcileAll();

    expect(WorkItemLink::query()->where('event_id', $event->id)->where('work_item_id', 'APP#90')->exists())->toBeTrue();
});

function reconciliationOperator(): User
{
    return User::factory()->create();
}

function attachTrackerProject(SoftwareSystem $system, string $trackerId, string $projectKey): void
{
    TrackerProjectLink::query()->create([
        'owner_type' => SoftwareSystem::class,
        'owner_id' => $system->id,
        'tracker_id' => $trackerId,
        'project_key' => $projectKey,
        'project_name' => $projectKey,
        'is_default' => false,
        'created_by_user_id' => null,
        'metadata' => null,
    ]);
}
