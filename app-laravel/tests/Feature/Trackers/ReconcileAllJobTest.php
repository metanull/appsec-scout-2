<?php

use App\Credentials\Vault;
use App\Events\SyncRunFinished;
use App\Models\ErrorLog;
use App\Models\SecurityEvent;
use App\Models\SyncRun;
use App\Models\WorkItemLink;
use App\Trackers\Dto\ProjectDto;
use App\Trackers\Dto\ReconciliationCandidateDto;
use App\Trackers\ReconcileAllJob;
use App\Trackers\Reconciliation\ReconciliationService;
use App\Trackers\Registry;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    // The sweep only searches trackers configured with system credentials.
    app(Vault::class)->set('fake-tracker.token', null, 'fake-token');
});

it('records a successful sweep as a reconciliation sync run with its link counts', function () {
    seedReconcileAllSweep();

    (new ReconcileAllJob)->handle(app(ReconciliationService::class));

    $run = SyncRun::query()->where('source_id', ReconcileAllJob::RUN_SOURCE_ID)->sole();

    expect(SyncRun::query()->where('source_id', ReconcileAllJob::RUN_SOURCE_ID)->count())->toBe(1)
        ->and($run->status)->toBe('success')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error_message)->toBeNull()
        ->and($run->counts_json)->toBe(['links_created' => 1, 'links_existing' => 1, 'trackers_reconciled' => 1, 'trackers_failed' => 0]);
});

it('records a sweep where one tracker failed as a partial run and keeps the other tracker links', function () {
    seedPartiallyFailingReconcileAllSweep();

    (new ReconcileAllJob)->handle(app(ReconciliationService::class));

    $run = SyncRun::query()->where('source_id', ReconcileAllJob::RUN_SOURCE_ID)->sole();

    expect($run->status)->toBe('partial')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error_message)->toContain('1 tracker(s) failed, 1 reconciled')
        ->and($run->error_message)->toContain('Reconciliation of tracker fake-broken failed')
        ->and($run->counts_json)->toBe(['links_created' => 1, 'links_existing' => 0, 'trackers_reconciled' => 1, 'trackers_failed' => 1])
        ->and(WorkItemLink::query()->where('work_item_id', 'OK#1')->exists())->toBeTrue()
        ->and(ErrorLog::query()->where('channel', 'reconciliation')->sole()->context_json)->toMatchArray(['tracker_id' => 'fake-broken']);
});

it('records a sweep where every tracker failed as a failure run without rethrowing', function () {
    seedFailingReconcileAllSweep();

    (new ReconcileAllJob)->handle(app(ReconciliationService::class));

    $run = SyncRun::query()->where('source_id', ReconcileAllJob::RUN_SOURCE_ID)->sole();

    expect($run->status)->toBe('failure')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error_message)->toContain('Reconciliation search failed')
        ->and($run->counts_json)->toBe(['links_created' => 0, 'links_existing' => 0, 'trackers_reconciled' => 0, 'trackers_failed' => 1])
        ->and(ErrorLog::query()->where('channel', 'reconciliation')->count())->toBe(1);
});

it('records a rate-limited tracker as failed with its reset time', function () {
    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationRateLimit(CarbonImmutable::parse('2026-10-19 12:00:00')));

    SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/OK%231']);

    (new ReconcileAllJob)->handle(app(ReconciliationService::class));

    $run = SyncRun::query()->where('source_id', ReconcileAllJob::RUN_SOURCE_ID)->sole();

    expect($run->status)->toBe('failure')
        ->and($run->error_message)->toContain('Rate limit exceeded')
        ->and($run->counts_json)->toBe(['links_created' => 0, 'links_existing' => 0, 'trackers_reconciled' => 0, 'trackers_failed' => 1]);
});

it('writes no reconciliation cache key', function () {
    seedReconcileAllSweep();

    (new ReconcileAllJob)->handle(app(ReconciliationService::class));

    expect(Cache::has('reconciliation:last_run_at'))->toBeFalse()
        ->and(Cache::has('reconciliation:last_run_new_links'))->toBeFalse();
});

it('fires SyncRunFinished when a sweep succeeds', function () {
    Event::fake([SyncRunFinished::class]);

    seedReconcileAllSweep();

    (new ReconcileAllJob)->handle(app(ReconciliationService::class));

    Event::assertDispatched(SyncRunFinished::class);
});

it('fires SyncRunFinished when a sweep fails', function () {
    Event::fake([SyncRunFinished::class]);

    seedFailingReconcileAllSweep();

    (new ReconcileAllJob)->handle(app(ReconciliationService::class));

    Event::assertDispatched(SyncRunFinished::class);
});

/**
 * Seeds a sweep that produces exactly one new link and one already-linked result:
 * two events, each matched by its own candidate, one of which is already linked.
 */
function seedReconcileAllSweep(): void
{
    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        jobCandidate('APP#1'),
        jobCandidate('APP#2'),
    ));

    SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%231']);
    $linkedEvent = SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%232']);

    WorkItemLink::query()->create([
        'event_id' => $linkedEvent->id,
        'tracker_id' => 'fake-tracker',
        'work_item_id' => 'APP#2',
        'work_item_url' => 'https://tracker.test/APP%232',
        'work_item_title' => 'Existing link',
        'work_item_state' => 'Open',
        'created_by_user_id' => null,
        'created_at' => now(),
        'synced_at' => now(),
    ]);
}

/** Seeds a sweep over two trackers where fake-broken fails and fake-tracker produces one new link. */
function seedPartiallyFailingReconcileAllSweep(): void
{
    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(jobCandidate('OK#1')));

    app(Vault::class)->set('fake-broken.token', null, 'broken-token');
    app()->bind('appsec-scout.tracker.fake-broken', fn () => (new FakeTracker('fake-broken'))->withReconciliationFailure());
    app()->tag(['appsec-scout.tracker.fake-broken'], 'appsec-scout.tracker');
    app()->forgetInstance(Registry::class);

    SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/OK%231']);
}

/** Seeds a sweep whose only tracker fails its search. */
function seedFailingReconcileAllSweep(): void
{
    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationFailure());

    SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%235']);
}

function jobCandidate(string $workItemId): ReconciliationCandidateDto
{
    $url = 'https://tracker.test/' . rawurlencode($workItemId);

    return new ReconciliationCandidateDto(
        trackerId: 'fake-tracker',
        workItemId: $workItemId,
        workItemUrl: $url,
        title: 'Issue ' . $workItemId,
        state: 'Open',
        labels: ['security'],
        extractedUrls: [$url],
        searchStrategy: 'labels=security',
        project: new ProjectDto(key: 'APP', name: 'APP'),
    );
}
