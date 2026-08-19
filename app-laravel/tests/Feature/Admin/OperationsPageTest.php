<?php

use App\Audit\AuditLog;
use App\Filament\Pages\OperationsPage;
use App\Filament\Resources\RepositoryCollectionRunResource;
use App\Filament\Resources\StaticAnalysisRunResource;
use App\Filament\Widgets\OperationsHealthStatsWidget;
use App\Models\ErrorLog;
use App\Models\RepositoryCollectionRun;
use App\Models\StaticAnalysisRun;
use App\Models\SyncRun;
use App\Models\User;
use App\SourceControl\Collection\DispatchRepositoryCollectionRunsJob;
use App\SourceControl\Collection\DispatchStaticAnalysisRunsJob;
use App\Sources\Registry as SourceRegistry;
use App\Sync\FetchSourceJob;
use App\Sync\SyncInventoryJob;
use App\Trackers\ReconcileAllJob;
use App\Trackers\RefreshWorkItemsJob;
use App\Trackers\Registry;
use Database\Seeders\RolePermissionSeeder;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\FakeSource;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    bindFakeOperationsIntegrations();
});

it('authorizes the operations page only for admins', function () {
    $reader = operationsUser();
    $this->actingAs($reader);

    expect(OperationsPage::canAccess())->toBeFalse();

    $sync = operationsUser();
    $sync->syncRoles(['Sync']);
    $this->actingAs($sync);

    expect(OperationsPage::canAccess())->toBeTrue();

    $admin = operationsAdmin();
    $this->actingAs($admin);

    expect(OperationsPage::canAccess())->toBeTrue();
});

it('shows queue failed-job sync-run and error counts', function () {
    $admin = operationsAdmin();

    config([
        'queue.default' => 'database',
        'queue.connections.database.queue' => 'default',
    ]);

    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{"job":"Example"}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Example","token":"secret-token"}',
        'exception' => "PDOException: SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'version_control_url' at row 1 insert into `security_events` values secret-token",
        'failed_at' => now(),
    ]);

    SyncRun::query()->create([
        'source_id' => 'fake',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['events_created' => 1, 'events_updated' => 0],
        'error_message' => null,
    ]);

    ErrorLog::query()->create([
        'channel' => 'sync',
        'level' => 'ERROR',
        'message' => 'Sync failed',
        'context_json' => null,
        'occurred_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertSee('Queued jobs')
        ->assertSee('Recent failed jobs')
        ->assertSee('Sync failed');
});

it('queues supported operational actions and records audit rows', function () {
    Bus::fake();

    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->callAction('fetchSource', data: ['source_id' => 'fake'])
        ->callAction('refreshTracker', data: ['tracker_id' => 'fake-tracker']);

    Bus::assertDispatched(FetchSourceJob::class);
    Bus::assertDispatched(RefreshWorkItemsJob::class);

    expect(AuditLog::query()->where('action', 'operations.dispatch_source_fetch')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'operations.dispatch_tracker_refresh')->exists())->toBeTrue();
});

it('lets a work-items.sync user dispatch source fetch and tracker refresh too', function () {
    Bus::fake();

    $sync = operationsUser();
    $sync->syncRoles(['Sync']);

    Livewire::actingAs($sync)
        ->test(OperationsPage::class)
        ->callAction('fetchSource', data: ['source_id' => 'fake'])
        ->callAction('refreshTracker', data: ['tracker_id' => 'fake-tracker']);

    Bus::assertDispatched(FetchSourceJob::class);
    Bus::assertDispatched(RefreshWorkItemsJob::class);
});

it('denies a user with neither admin.queue nor work-items.sync from invoking Operations actions directly, bypassing button visibility', function () {
    Bus::fake();

    $reader = operationsUser();
    $reader->syncRoles(['Reader']);

    ErrorLog::query()->create([
        'channel' => 'sync', 'level' => 'ERROR', 'message' => 'seed', 'occurred_at' => now()->subDays(400),
    ]);
    AuditLog::query()->create(['actor_kind' => 'system', 'action' => 'seed', 'user_id' => null, 'ip' => null])
        ->forceFill(['created_at' => now()->subDays(400)])->save();
    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()->subDays(400),
    ]);

    $component = Livewire::actingAs($reader)->test(OperationsPage::class);
    $component->call('dispatchSelectedSource', 'fake');
    $component->call('dispatchSelectedTracker', 'fake-tracker');
    $component->call('dispatchReconcileAll');
    $component->call('pruneAuditLogsNow');
    $component->call('pruneErrorLogsNow');
    $component->call('pruneFailedJobsNow');

    Bus::assertNotDispatched(FetchSourceJob::class);
    Bus::assertNotDispatched(RefreshWorkItemsJob::class);
    Bus::assertNotDispatched(ReconcileAllJob::class);

    expect(ErrorLog::count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'seed')->exists())->toBeTrue()
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'operations.dispatch_source_fetch')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'operations.prune_audit_logs')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'operations.prune_error_logs')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'operations.prune_failed_jobs')->exists())->toBeFalse();
});

it('header actions render for admin', function () {
    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertActionExists('fetchSource')
        ->assertActionExists('refreshTracker')
        ->assertActionExists('syncInventory');
});

it('admin users can trigger the inventory sync action', function () {
    Bus::fake();

    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->call('dispatchSyncInventory');

    Bus::assertDispatched(SyncInventoryJob::class);

    expect(AuditLog::query()->where('action', 'operations.sync_inventory')->exists())->toBeTrue();
});

it('does not dispatch inventory sync when it is already queued', function () {
    Bus::fake();

    $admin = operationsAdmin();

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => SyncInventoryJob::class], JSON_THROW_ON_ERROR),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->call('dispatchSyncInventory');

    Bus::assertNotDispatched(SyncInventoryJob::class);
});

it('shows inventory sync last-run summary on operations page', function () {
    $admin = operationsAdmin();

    Cache::put('inventory_sync:last_run_at', now()->toIso8601String());
    Cache::put('inventory_sync:last_run_counts', ['systems_created' => 2, 'systems_updated' => 1, 'containers_created' => 3, 'containers_updated' => 0]);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertSee('Inventory sync')
        ->assertSee('3 system(s), 3 container(s) synced');
});

it('colors the inventory sync stat as warning when the last run found nothing', function () {
    Cache::put('inventory_sync:last_run_at', now()->toIso8601String());
    Cache::put('inventory_sync:last_run_counts', ['systems_created' => 0, 'systems_updated' => 0, 'containers_created' => 0, 'containers_updated' => 0]);

    $method = new ReflectionMethod(OperationsHealthStatsWidget::class, 'inventorySyncStat');
    $method->setAccessible(true);

    /** @var Stat $stat */
    $stat = $method->invoke(new OperationsHealthStatsWidget);

    expect($stat->getColor())->toBe('warning');
});

it('colors the inventory sync stat as success when the last run found something', function () {
    Cache::put('inventory_sync:last_run_at', now()->toIso8601String());
    Cache::put('inventory_sync:last_run_counts', ['systems_created' => 1, 'systems_updated' => 0, 'containers_created' => 0, 'containers_updated' => 0]);

    $method = new ReflectionMethod(OperationsHealthStatsWidget::class, 'inventorySyncStat');
    $method->setAccessible(true);

    /** @var Stat $stat */
    $stat = $method->invoke(new OperationsHealthStatsWidget);

    expect($stat->getColor())->toBe('success');
});

it('sync users can trigger global reconciliation action', function () {
    Bus::fake();

    $sync = operationsUser();
    $sync->syncRoles(['Sync']);

    Livewire::actingAs($sync)
        ->test(OperationsPage::class)
        ->call('dispatchReconcileAll');

    Bus::assertDispatched(ReconcileAllJob::class);
});

it('does not dispatch global reconciliation when it is already queued', function () {
    Bus::fake();

    $sync = operationsUser();
    $sync->syncRoles(['Sync']);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => ReconcileAllJob::class], JSON_THROW_ON_ERROR),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    Livewire::actingAs($sync)
        ->test(OperationsPage::class)
        ->call('dispatchReconcileAll');

    Bus::assertNotDispatched(ReconcileAllJob::class);
});

it('shows reconciliation last-run summary on operations page', function () {
    $admin = operationsAdmin();

    Cache::put('reconciliation:last_run_at', now()->toIso8601String());
    Cache::put('reconciliation:last_run_new_links', 3);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertSee('Reconciliation')
        ->assertSee('3 new link(s) created');
});

it('shows only the reconciliation stat to a work-items.sync-only user', function () {
    $sync = operationsUser();
    $sync->syncRoles(['Sync']);

    Livewire::actingAs($sync)
        ->test(OperationsPage::class)
        ->assertSee('Reconciliation')
        ->assertDontSee('Inventory sync')
        ->assertDontSee('Jobs queued or currently running')
        ->assertDontSee('Failed jobs needing attention');
});

it('shows all four operations health stats to an admin.queue user', function () {
    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertSee('Reconciliation')
        ->assertSee('Inventory sync')
        ->assertSee('Jobs queued or currently running')
        ->assertSee('Failed jobs needing attention');
});

it('header action dispatches source by form data', function () {
    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->callAction('fetchSource', data: ['source_id' => 'fake']);

    expect(AuditLog::query()->where('action', 'operations.dispatch_source_fetch')->exists())->toBeTrue();
});

it('header action dispatches tracker by form data', function () {
    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->callAction('refreshTracker', data: ['tracker_id' => 'fake-tracker']);

    expect(AuditLog::query()->where('action', 'operations.dispatch_tracker_refresh')->exists())->toBeTrue();
});

it('header actions include collect repositories for admin', function () {
    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertActionExists('collectRepositories');
});

it('admin users can trigger the collect repositories action', function () {
    Bus::fake();

    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->call('dispatchCollectRepositories');

    Bus::assertDispatched(DispatchRepositoryCollectionRunsJob::class);

    expect(AuditLog::query()->where('action', 'operations.collect_repositories')->exists())->toBeTrue();
});

it('does not dispatch repository collection when a run is already in progress', function () {
    Bus::fake();

    $admin = operationsAdmin();

    RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now(),
        'status' => 'running',
        'counts_json' => [],
    ]);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->call('dispatchCollectRepositories');

    Bus::assertNotDispatched(DispatchRepositoryCollectionRunsJob::class);
});

it('dispatches repository collection again once a wedged run is force-finished', function () {
    Bus::fake();

    $admin = operationsAdmin();

    $wedged = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subHours(6),
        'status' => 'running',
        'counts_json' => [],
    ]);

    RepositoryCollectionRunResource::forceFinishRun($wedged);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->call('dispatchCollectRepositories');

    Bus::assertDispatched(DispatchRepositoryCollectionRunsJob::class);
});

it('shows recent repository collection runs on the operations page', function () {
    $admin = operationsAdmin();

    RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['repositories_considered' => 4, 'repositories_failed' => 1],
        'error_message' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertSee('Recent Repository Collection Runs')
        ->assertSee('azdo-repos');
});

it('header actions include run static analysis for admin', function () {
    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertActionExists('runStaticAnalysis');
});

it('admin users can trigger the run static analysis action', function () {
    Bus::fake();

    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->call('dispatchRunStaticAnalysis');

    Bus::assertDispatched(DispatchStaticAnalysisRunsJob::class);

    expect(AuditLog::query()->where('action', 'operations.run_static_analysis')->exists())->toBeTrue();
});

it('does not dispatch static analysis when a run is already in progress', function () {
    Bus::fake();

    $admin = operationsAdmin();

    StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now(),
        'status' => 'running',
        'counts_json' => [],
    ]);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->call('dispatchRunStaticAnalysis');

    Bus::assertNotDispatched(DispatchStaticAnalysisRunsJob::class);
});

it('dispatches static analysis again once a wedged run is force-finished', function () {
    Bus::fake();

    $admin = operationsAdmin();

    $wedged = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subHours(6),
        'status' => 'running',
        'counts_json' => [],
    ]);

    StaticAnalysisRunResource::forceFinishRun($wedged);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->call('dispatchRunStaticAnalysis');

    Bus::assertDispatched(DispatchStaticAnalysisRunsJob::class);
});

it('shows recent static analysis runs on the operations page', function () {
    $admin = operationsAdmin();

    StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['repositories_considered' => 4, 'repositories_failed' => 1],
        'error_message' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertSee('Recent Static Analysis Runs')
        ->assertSee('azdo-repos');
});

it('hides recent sync runs, repository collection runs, and static analysis runs from a work-items.sync-only user', function () {
    $sync = operationsUser();
    $sync->syncRoles(['Sync']);

    RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    Livewire::actingAs($sync)
        ->test(OperationsPage::class)
        ->assertDontSee('Recent Sync Runs')
        ->assertDontSee('Recent Repository Collection Runs')
        ->assertDontSee('Recent Static Analysis Runs');
});

it('shows recent sync runs, repository collection runs, and static analysis runs to an admin.queue user', function () {
    $admin = operationsAdmin();

    RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertSee('Recent Sync Runs')
        ->assertSee('Recent Repository Collection Runs')
        ->assertSee('Recent Static Analysis Runs');
});

it('prunes failed jobs older than the configured retention window, audits, and notifies', function () {
    $admin = operationsAdmin();
    config(['queue.failed.retain_days' => 30]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Old"}',
        'exception' => 'boom',
        'failed_at' => now()->subDays(45),
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Recent"}',
        'exception' => 'boom',
        'failed_at' => now()->subDays(10),
    ]);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->call('pruneFailedJobsNow')
        ->assertNotified('1 failed job(s) deleted');

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->first()->payload)->toBe('{"job":"Recent"}');

    $audit = AuditLog::query()->where('action', 'operations.prune_failed_jobs')->firstOrFail();
    expect($audit->payload_json)->toBe(['deleted' => 1, 'retain_days' => 30]);
});

function bindFakeOperationsIntegrations(): void
{
    app()->bind('appsec-scout.source.fake', fn () => new FakeSource);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    app()->bind('appsec-scout.tracker.fake', fn () => new FakeTracker);
    app()->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    app()->forgetInstance(SourceRegistry::class);
    app()->forgetInstance(Registry::class);
}

function operationsAdmin(): User
{
    $user = operationsUser();
    $user->syncRoles(['Admin']);

    return $user;
}

function operationsUser(): User
{
    return User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
}
