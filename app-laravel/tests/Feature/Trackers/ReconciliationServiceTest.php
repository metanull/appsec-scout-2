<?php

use App\Credentials\Vault;
use App\Models\SecurityContainer;
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
    // Reconciliation only discovers projects for trackers whose credentials are configured.
    app(Vault::class)->set('fake-tracker.token', null, 'fake-token');
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

it('searches an enabled tracker project that has no existing TrackerProjectLink, produces a match, and auto-creates the link', function () {
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

    $link = TrackerProjectLink::query()
        ->where('owner_type', SoftwareSystem::class)
        ->where('owner_id', $event->softwareSystem->id)
        ->where('tracker_id', 'fake-tracker')
        ->where('project_key', 'UNLINKED')
        ->first();

    expect($link)->not->toBeNull()
        ->and($link->project_name)->toBe('Unlinked Project');
});

it('uses the scoped fast path on the next reconciliation run after an auto-created link', function () {
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

    app(ReconciliationService::class)->reconcileAll();

    $tracker->fetchProjectsCalls = 0;

    $results = app(ReconciliationService::class)->reconcileEvent($event, reconciliationOperator()->id);

    expect(collect($results)->firstWhere('alreadyLinked', true))->not->toBeNull()
        ->and($tracker->fetchProjectsCalls)->toBe(0);
});

it('fails reconciliation instead of silently skipping when fetchProjects throws for an enabled tracker', function () {
    $tracker = (new FakeTracker)
        ->withFetchProjectsFailure()
        ->withReconciliationCandidates('APP', new ReconciliationCandidateDto(
            trackerId: 'fake-tracker',
            workItemId: 'APP#5',
            workItemUrl: 'https://tracker.test/APP%235',
            title: 'Should not be reached',
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

    expect(fn () => app(ReconciliationService::class)->reconcileAll())
        ->toThrow(RuntimeException::class, 'Failed to list projects');

    expect(WorkItemLink::query()->where('event_id', $event->id)->where('work_item_id', 'APP#5')->exists())->toBeFalse();
});

it('fails reconciliation instead of silently skipping when one project search throws', function () {
    $tracker = (new FakeTracker)
        ->withReconciliationFailure('BROKEN')
        ->withReconciliationCandidates('OK', new ReconciliationCandidateDto(
            trackerId: 'fake-tracker',
            workItemId: 'OK#1',
            workItemUrl: 'https://tracker.test/OK%231',
            title: 'Should not be reached',
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

    expect(fn () => app(ReconciliationService::class)->reconcileAll())
        ->toThrow(RuntimeException::class, 'Reconciliation failed for project BROKEN');

    expect(WorkItemLink::query()->where('event_id', $event->id)->where('work_item_id', 'OK#1')->exists())->toBeFalse();
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

it('creates no link when a work item only references the repository root', function () {
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

    $results = app(ReconciliationService::class)->reconcileAll();

    expect($results)->toBe([])
        ->and(WorkItemLink::query()->where('event_id', $event->id)->exists())->toBeFalse();
});

it('regression SEC-816: does not link a grouped issue to alerts of a sibling repository in the same project', function () {
    $projectRoot = 'https://dev.azure.com/acme/Agora';
    $pocAlertOne = 'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/194';
    $pocAlertTwo = 'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/398';
    $siblingAlert = 'https://dev.azure.com/acme/Agora/_git/Agora/alerts/11908';

    $tracker = (new FakeTracker)->withReconciliationCandidates('SEC', new ReconciliationCandidateDto(
        trackerId: 'fake-tracker',
        workItemId: 'SEC-816',
        workItemUrl: 'https://tracker.test/SEC-816',
        title: 'Agora: agora-event-grid-poc: Secret (2 alerts, 2 files)',
        state: 'Open',
        labels: ['security'],
        // A grouped issue body renders alert, project root and repository root per occurrence.
        extractedUrls: [
            $pocAlertOne,
            $projectRoot,
            'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc',
            $pocAlertTwo,
        ],
        searchStrategy: 'project=SEC',
    ));
    bindFakeWorkItemTracker($tracker);

    $system = SoftwareSystem::factory()->create();
    $pocContainer = SecurityContainer::factory()->forSystem($system)->create(['name' => 'agora-event-grid-poc']);
    $agoraContainer = SecurityContainer::factory()->forSystem($system)->create(['name' => 'Agora']);

    $firstPocEvent = azdoAlertEvent($pocContainer, $pocAlertOne, 'agora-event-grid-poc', 'repo-guid-poc');
    $secondPocEvent = azdoAlertEvent($pocContainer, $pocAlertTwo, 'agora-event-grid-poc', 'repo-guid-poc');
    $siblingEvent = azdoAlertEvent($agoraContainer, $siblingAlert, 'Agora', 'repo-guid-agora');

    attachTrackerProject($system, 'fake-tracker', 'SEC');

    app(ReconciliationService::class)->reconcileAll();

    expect(WorkItemLink::query()->where('work_item_id', 'SEC-816')->pluck('event_id')->map(fn ($id): int => (int) $id)->sort()->values()->all())
        ->toBe([(int) $firstPocEvent->id, (int) $secondPocEvent->id])
        ->and(WorkItemLink::query()->where('event_id', $siblingEvent->id)->exists())->toBeFalse();
});

it('links a candidate carrying the guid form of an alert url to an event stored in name form', function () {
    $tracker = (new FakeTracker)->withReconciliationCandidates('SEC', new ReconciliationCandidateDto(
        trackerId: 'fake-tracker',
        workItemId: 'SEC-900',
        workItemUrl: 'https://tracker.test/SEC-900',
        title: 'Guid form reference',
        state: 'Open',
        labels: ['security'],
        extractedUrls: ['https://dev.azure.com/acme/project-guid-agora/_git/repo-guid-poc/alerts/398'],
        searchStrategy: 'project=SEC',
    ));
    bindFakeWorkItemTracker($tracker);

    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'agora-event-grid-poc']);

    $event = azdoAlertEvent(
        $container,
        'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/398',
        'agora-event-grid-poc',
        'repo-guid-poc',
    );

    attachTrackerProject($system, 'fake-tracker', 'SEC');

    app(ReconciliationService::class)->reconcileAll();

    expect(WorkItemLink::query()->where('work_item_id', 'SEC-900')->pluck('event_id')->map(fn ($id): int => (int) $id)->all())->toBe([(int) $event->id]);
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

function azdoAlertEvent(SecurityContainer $container, string $alertUrl, string $repositoryName, string $repositoryId): SecurityEvent
{
    return SecurityEvent::factory()->forContainer($container)->create([
        'source_id' => 'azdo',
        'url' => $alertUrl,
        'version_control_url' => 'https://dev.azure.com/acme/Agora/_git/' . $repositoryName . '?path=/src/Main.java',
        'metadata' => [
            'source' => ['alert' => ['web_url' => $alertUrl]],
            'azdo' => [
                'project' => ['id' => 'project-guid-agora', 'name' => 'Agora'],
                'repository' => ['id' => $repositoryId, 'name' => $repositoryName],
            ],
            'links' => [
                ['label' => 'Source alert', 'url' => $alertUrl],
                ['label' => 'Rule documentation', 'url' => 'https://docs.example.com/rules/java-ssrf'],
            ],
        ],
    ]);
}
