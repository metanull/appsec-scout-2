<?php

use App\Credentials\Vault;
use App\Events\SyncRunFinished;
use App\Models\ErrorLog;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SyncRun;
use App\Models\TrackerProjectLink;
use App\Models\WorkItemLink;
use App\Trackers\Dto\ReconciliationCandidateDto;
use App\Trackers\ReconcileAllJob;
use App\Trackers\Reconciliation\ReconciliationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    // Reconciliation only discovers projects for trackers whose credentials are configured.
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
        ->and($run->counts_json)->toBe(['links_created' => 1, 'links_existing' => 1]);
});

it('records a failed sweep as a failure run with an error log and rethrows', function () {
    seedFailingReconcileAllSweep();

    expect(fn () => (new ReconcileAllJob)->handle(app(ReconciliationService::class)))
        ->toThrow(RuntimeException::class, 'Failed to list projects');

    $run = SyncRun::query()->where('source_id', ReconcileAllJob::RUN_SOURCE_ID)->sole();

    expect($run->status)->toBe('failure')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error_message)->toContain('Failed to list projects')
        ->and($run->counts_json)->toBe(['links_created' => 0, 'links_existing' => 0])
        ->and(ErrorLog::query()->where('channel', 'reconciliation')->where('message', 'like', '%Failed to list projects%')->exists())->toBeTrue();
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

    expect(fn () => (new ReconcileAllJob)->handle(app(ReconciliationService::class)))
        ->toThrow(RuntimeException::class);

    Event::assertDispatched(SyncRunFinished::class);
});

/**
 * Seeds a sweep that produces exactly one new link and one already-linked result:
 * two events, each matched by its own candidate, one of which is already linked.
 */
function seedReconcileAllSweep(): void
{
    $tracker = (new FakeTracker)->withReconciliationCandidates(
        'APP',
        new ReconciliationCandidateDto(
            trackerId: 'fake-tracker',
            workItemId: 'APP#1',
            workItemUrl: 'https://tracker.test/APP%231',
            title: 'New link',
            state: 'Open',
            labels: ['security'],
            extractedUrls: ['https://tracker.test/APP%231'],
            searchStrategy: 'project=APP',
        ),
        new ReconciliationCandidateDto(
            trackerId: 'fake-tracker',
            workItemId: 'APP#2',
            workItemUrl: 'https://tracker.test/APP%232',
            title: 'Existing link',
            state: 'Open',
            labels: ['security'],
            extractedUrls: ['https://tracker.test/APP%232'],
            searchStrategy: 'project=APP',
        ),
    );
    bindFakeWorkItemTracker($tracker);

    $newEvent = SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%231']);
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

    foreach ([$newEvent, $linkedEvent] as $event) {
        TrackerProjectLink::query()->create([
            'owner_type' => SoftwareSystem::class,
            'owner_id' => $event->softwareSystem->id,
            'tracker_id' => 'fake-tracker',
            'project_key' => 'APP',
            'project_name' => 'APP',
            'is_default' => false,
            'created_by_user_id' => null,
            'metadata' => null,
        ]);
    }
}

/** Seeds a sweep whose tracker fails to list projects, so reconcileAll() throws. */
function seedFailingReconcileAllSweep(): void
{
    bindFakeWorkItemTracker((new FakeTracker)->withFetchProjectsFailure());

    $event = SecurityEvent::factory()->secret()->create(['url' => 'https://tracker.test/APP%235']);

    TrackerProjectLink::query()->create([
        'owner_type' => SoftwareSystem::class,
        'owner_id' => $event->softwareSystem->id,
        'tracker_id' => 'fake-tracker',
        'project_key' => 'APP',
        'project_name' => 'APP',
        'is_default' => false,
        'created_by_user_id' => null,
        'metadata' => null,
    ]);
}
