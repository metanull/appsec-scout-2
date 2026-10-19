<?php

use App\Audit\AuditLog;
use App\Credentials\Vault;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\TrackerProjectLink;
use App\Models\User;
use App\Models\WorkItemLink;
use App\Trackers\Dto\ProjectDto;
use App\Trackers\Dto\ReconciliationCandidateDto;
use App\Trackers\Reconciliation\ReconciliationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    // The sweep only searches trackers configured with system credentials.
    app(Vault::class)->set('fake-tracker.token', null, 'system-token');
});

it('links a work item found by the tracker-wide search, with the system credential', function () {
    $tracker = bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        reconciliationCandidate('APP#101', ['https://tracker.test/APP%23101']),
    ));

    $event = SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%23101']);

    $sweep = app(ReconciliationService::class)->reconcileAll();

    expect($sweep->linksCreated())->toBe(1)
        ->and($sweep->trackersReconciled)->toBe(1)
        ->and($tracker->reconciliationTokens)->toBe(['system-token'])
        ->and(WorkItemLink::query()->where('event_id', $event->id)->where('work_item_id', 'APP#101')->value('created_by_user_id'))->toBeNull();
});

it('searches each tracker once and never lists or scopes by projects', function () {
    $tracker = bindFakeWorkItemTracker(new FakeTracker);

    $event = SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%2310']);
    attachTrackerProject($event->softwareSystem, 'fake-tracker', 'APP');

    $sweep = app(ReconciliationService::class)->reconcileAll();

    expect($sweep->results)->toBe([])
        ->and($tracker->reconciliationCalls)->toBe(1)
        ->and($tracker->fetchProjectsCalls)->toBe(0);
});

it('does not sweep a tracker that has no system credentials', function () {
    app(Vault::class)->forget('fake-tracker.token', null);
    $tracker = bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        reconciliationCandidate('APP#1', ['https://tracker.test/APP%231']),
    ));

    SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%231']);

    $sweep = app(ReconciliationService::class)->reconcileAll();

    expect($tracker->reconciliationCalls)->toBe(0)
        ->and($sweep->trackersReconciled)->toBe(0)
        ->and($sweep->trackersFailed())->toBe(0);
});

it('skips already existing work item links', function () {
    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        reconciliationCandidate('APP#77', ['https://tracker.test/APP%2377']),
    ));

    $event = SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%2377']);
    existingWorkItemLink($event, 'APP#77');

    $results = app(ReconciliationService::class)->reconcileAll()->results;

    expect($results)->toHaveCount(1)
        ->and($results[0]->alreadyLinked)->toBeTrue()
        ->and(WorkItemLink::query()->where('event_id', $event->id)->where('work_item_id', 'APP#77')->count())->toBe(1);
});

it('learns the matched work item project as a link of the alert system and container', function () {
    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        reconciliationCandidate('SEC#1', ['https://tracker.test/SEC%231'], 'SEC'),
    ));

    $container = SecurityContainer::factory()->forSystem(SoftwareSystem::factory()->create())->create();
    $event = SecurityEvent::factory()->secret()->forContainer($container)->create(['url' => 'https://tracker.test/SEC%231']);

    app(ReconciliationService::class)->reconcileAll();

    $links = TrackerProjectLink::query()->where('tracker_id', 'fake-tracker')->where('project_key', 'SEC')->get();

    expect($links->map(fn (TrackerProjectLink $link): array => [$link->owner_type, (int) $link->owner_id])->sort()->values()->all())
        ->toBe(collect([[SecurityContainer::class, (int) $event->container_id], [SoftwareSystem::class, (int) $event->software_system_id]])->sort()->values()->all());

    $link = $links->firstWhere('owner_type', SoftwareSystem::class);

    expect($link->project_name)->toBe('SEC project')
        ->and($link->projectUrl())->toBe('https://tracker.test/projects/SEC')
        ->and($link->origin())->toBe(TrackerProjectLink::ORIGIN_RECONCILIATION)
        ->and($link->isLearnedByReconciliation())->toBeTrue()
        ->and($link->is_default)->toBeFalse()
        ->and($link->created_by_user_id)->toBeNull();

    $audit = AuditLog::query()->where('action', 'tracker_project_link_created')->get()
        ->first(fn (AuditLog $row): bool => ($row->payload_json['tracker_project_link_id'] ?? null) === $link->id);

    expect($audit)->not->toBeNull()
        ->and($audit->payload_json['origin'])->toBe(TrackerProjectLink::ORIGIN_RECONCILIATION);
});

it('learns the project of a work item that was already linked', function () {
    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        reconciliationCandidate('MPLUS#722', ['https://tracker.test/MPLUS%23722'], 'MPLUS'),
    ));

    $event = SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/MPLUS%23722']);
    existingWorkItemLink($event, 'MPLUS#722');

    app(ReconciliationService::class)->reconcileAll();

    expect(TrackerProjectLink::query()
        ->where('owner_type', SoftwareSystem::class)
        ->where('owner_id', $event->software_system_id)
        ->where('project_key', 'MPLUS')
        ->exists())->toBeTrue();
});

it('keeps the origin of an existing link while adding the project url', function () {
    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        reconciliationCandidate('APP#5', ['https://tracker.test/APP%235']),
    ));

    $event = SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%235']);
    attachTrackerProject($event->softwareSystem, 'fake-tracker', 'APP');

    app(ReconciliationService::class)->reconcileAll();

    $link = TrackerProjectLink::query()
        ->where('owner_type', SoftwareSystem::class)
        ->where('owner_id', $event->software_system_id)
        ->where('project_key', 'APP')
        ->sole();

    expect($link->origin())->toBeNull()
        ->and($link->isLearnedByReconciliation())->toBeFalse()
        ->and($link->projectUrl())->toBe('https://tracker.test/projects/APP');
});

it('records a failing tracker search as a sweep failure', function () {
    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationFailure());

    SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%235']);

    $sweep = app(ReconciliationService::class)->reconcileAll();

    expect($sweep->trackersReconciled)->toBe(0)
        ->and($sweep->trackersFailed())->toBe(1)
        ->and($sweep->failures[0]->trackerId)->toBe('fake-tracker');

    $error = ErrorLog::query()->where('channel', 'reconciliation')->sole();

    expect($error->message)->toBe('Reconciliation of tracker fake-tracker failed: Reconciliation search failed')
        ->and($error->context_json)->toMatchArray(['source_id' => 'reconciliation', 'tracker_id' => 'fake-tracker'])
        ->and($error->trace)->not->toBeEmpty();
});

it('records a rate-limited tracker as a sweep failure', function () {
    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationRateLimit(CarbonImmutable::parse('2026-10-19 12:00:00')));

    SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%235']);

    $sweep = app(ReconciliationService::class)->reconcileAll();

    expect($sweep->trackersFailed())->toBe(1)
        ->and($sweep->failureSummary())->toContain('Rate limit exceeded');
});

it('reconciles a single alert with the operator own credential only', function () {
    $operator = reconciliationOperator();
    app(Vault::class)->set('fake-tracker.token', $operator->id, 'user-token');
    $tracker = bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        reconciliationCandidate('APP#101', ['https://tracker.test/APP%23101']),
    ));

    $event = SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%23101']);

    $results = app(ReconciliationService::class)->reconcileEvent($event, $operator->id);

    expect($results)->toHaveCount(1)
        ->and($results[0]->alreadyLinked)->toBeFalse()
        ->and($tracker->reconciliationTokens)->toBe(['user-token'])
        ->and(WorkItemLink::query()->where('event_id', $event->id)->value('created_by_user_id'))->toBe($operator->id)
        ->and(TrackerProjectLink::query()->where('project_key', 'APP')->pluck('created_by_user_id')->unique()->all())->toBe([$operator->id]);
});

it('refuses a single-alert reconciliation for an operator without tracker credentials, despite system credentials', function () {
    $tracker = bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        reconciliationCandidate('APP#101', ['https://tracker.test/APP%23101']),
    ));

    $event = SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%23101']);

    expect(fn () => app(ReconciliationService::class)->reconcileEvent($event, reconciliationOperator()->id))
        ->toThrow(RuntimeException::class, 'No tracker is configured with your own credentials');

    expect($tracker->reconciliationCalls)->toBe(0)
        ->and(WorkItemLink::query()->exists())->toBeFalse();
});

it('fails a single-alert reconciliation when the tracker search fails', function () {
    $operator = reconciliationOperator();
    app(Vault::class)->set('fake-tracker.token', $operator->id, 'user-token');
    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationFailure());

    $event = SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%235']);

    expect(fn () => app(ReconciliationService::class)->reconcileEvent($event, $operator->id))
        ->toThrow(RuntimeException::class, 'Reconciliation search failed');
});

it('creates links for multiple events matching one work item', function () {
    $sharedUrl = 'https://tracker.test/APP%2350';

    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        reconciliationCandidate('APP#50', [$sharedUrl]),
    ));

    $first = SecurityEvent::factory()->secret()->create(['url' => $sharedUrl]);
    $second = SecurityEvent::factory()->secret()->create(['url' => $sharedUrl]);

    app(ReconciliationService::class)->reconcileAll();

    expect(WorkItemLink::query()->where('work_item_id', 'APP#50')->pluck('event_id')->all())
        ->toContain($first->id, $second->id);
});

it('creates no link when a work item only references the repository root', function () {
    $repoRoot = 'https://dev.azure.com/acme/proj/_git/repo';

    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        reconciliationCandidate('APP#90', [$repoRoot]),
    ));

    $event = SecurityEvent::factory()->secret()->create(['url' => $repoRoot . '/alerts/123']);

    $results = app(ReconciliationService::class)->reconcileAll()->results;

    expect($results)->toBe([])
        ->and(WorkItemLink::query()->where('event_id', $event->id)->exists())->toBeFalse()
        ->and(TrackerProjectLink::query()->exists())->toBeFalse();
});

it('regression SEC-816: does not link a grouped issue to alerts of a sibling repository in the same project', function () {
    $projectRoot = 'https://dev.azure.com/acme/Agora';
    $pocAlertOne = 'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/194';
    $pocAlertTwo = 'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/398';
    $siblingAlert = 'https://dev.azure.com/acme/Agora/_git/Agora/alerts/11908';

    // A grouped issue body renders alert, project root and repository root per occurrence.
    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        reconciliationCandidate('SEC-816', [
            $pocAlertOne,
            $projectRoot,
            'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc',
            $pocAlertTwo,
        ], 'SEC'),
    ));

    $system = SoftwareSystem::factory()->create();
    $pocContainer = SecurityContainer::factory()->forSystem($system)->create(['name' => 'agora-event-grid-poc']);
    $agoraContainer = SecurityContainer::factory()->forSystem($system)->create(['name' => 'Agora']);

    $firstPocEvent = azdoAlertEvent($pocContainer, $pocAlertOne, 'agora-event-grid-poc', 'repo-guid-poc');
    $secondPocEvent = azdoAlertEvent($pocContainer, $pocAlertTwo, 'agora-event-grid-poc', 'repo-guid-poc');
    $siblingEvent = azdoAlertEvent($agoraContainer, $siblingAlert, 'Agora', 'repo-guid-agora');

    app(ReconciliationService::class)->reconcileAll();

    expect(WorkItemLink::query()->where('work_item_id', 'SEC-816')->pluck('event_id')->map(fn ($id): int => (int) $id)->sort()->values()->all())
        ->toBe([(int) $firstPocEvent->id, (int) $secondPocEvent->id])
        ->and(WorkItemLink::query()->where('event_id', $siblingEvent->id)->exists())->toBeFalse()
        ->and(TrackerProjectLink::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $agoraContainer->id)->exists())->toBeFalse();
});

it('links a candidate carrying the guid form of an alert url to an event stored in name form', function () {
    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        reconciliationCandidate('SEC-900', ['https://dev.azure.com/acme/project-guid-agora/_git/repo-guid-poc/alerts/398'], 'SEC'),
    ));

    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'agora-event-grid-poc']);

    $event = azdoAlertEvent(
        $container,
        'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/398',
        'agora-event-grid-poc',
        'repo-guid-poc',
    );

    app(ReconciliationService::class)->reconcileAll();

    expect(WorkItemLink::query()->where('work_item_id', 'SEC-900')->pluck('event_id')->map(fn ($id): int => (int) $id)->all())->toBe([(int) $event->id]);
});

function reconciliationOperator(): User
{
    return User::factory()->create();
}

/** @param  list<string>  $urls */
function reconciliationCandidate(string $workItemId, array $urls, string $projectKey = 'APP'): ReconciliationCandidateDto
{
    return new ReconciliationCandidateDto(
        trackerId: 'fake-tracker',
        workItemId: $workItemId,
        workItemUrl: 'https://tracker.test/' . rawurlencode($workItemId),
        title: 'Security issue ' . $workItemId,
        state: 'Open',
        labels: ['security'],
        extractedUrls: $urls,
        searchStrategy: 'labels=security',
        project: new ProjectDto(key: $projectKey, name: $projectKey . ' project', url: 'https://tracker.test/projects/' . $projectKey),
    );
}

function existingWorkItemLink(SecurityEvent $event, string $workItemId): void
{
    WorkItemLink::query()->create([
        'event_id' => $event->id,
        'tracker_id' => 'fake-tracker',
        'work_item_id' => $workItemId,
        'work_item_url' => 'https://tracker.test/' . rawurlencode($workItemId),
        'work_item_title' => 'Existing issue',
        'work_item_state' => 'Open',
        'created_by_user_id' => null,
        'created_at' => now(),
        'synced_at' => now(),
    ]);
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
