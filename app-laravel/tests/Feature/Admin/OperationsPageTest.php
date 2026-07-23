<?php

use App\Audit\AuditLog;
use App\Filament\Pages\OperationsPage;
use App\Filament\Widgets\OperationsHealthStatsWidget;
use App\Models\ErrorLog;
use App\Models\SyncRun;
use App\Models\User;
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
use Illuminate\Support\Facades\File;
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

    $page = Livewire::actingAs($admin)->test(OperationsPage::class)->instance();

    expect($page->queuedJobCount())->toBe(1)
        ->and($page->failedJobCount())->toBe(1);
});

it('counts queued jobs across configured queue names', function () {
    $admin = operationsAdmin();

    config([
        'queue.default' => 'database',
        'queue.connections.database.queue' => 'default,high',
    ]);

    DB::table('jobs')->delete();

    DB::table('jobs')->insert([
        [
            'queue' => 'default',
            'payload' => '{"job":"Example"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ],
        [
            'queue' => 'high',
            'payload' => '{"job":"Example"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ],
    ]);

    $page = Livewire::actingAs($admin)->test(OperationsPage::class)->instance();

    expect($page->queuedJobCount())->toBe(2);
});

it('queues supported operational actions and records audit rows', function () {
    Bus::fake();

    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->set('selectedSourceId', 'fake')
        ->set('selectedTrackerId', 'fake-tracker')
        ->call('dispatchSelectedSource')
        ->call('dispatchSelectedTracker');

    Bus::assertDispatched(FetchSourceJob::class);
    Bus::assertDispatched(RefreshWorkItemsJob::class);

    expect(AuditLog::query()->where('action', 'operations.dispatch_source_fetch')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'operations.dispatch_tracker_refresh')->exists())->toBeTrue();
});

it('shows sbom scan status on the operations page', function () {
    $admin = operationsAdmin();

    $importPath = sys_get_temp_dir() . '/sbom-status-page-test-' . uniqid();
    $cursorPath = sys_get_temp_dir() . '/sbom-status-page-cursor-test-' . uniqid();
    File::ensureDirectoryExists($importPath . '/20260101T000000Z');
    File::put(
        $importPath . '/20260101T000000Z/run.jsonl',
        json_encode(['project' => 'Payments', 'repository' => 'payments-api'], JSON_THROW_ON_ERROR) . "\n",
    );
    config(['sbom.import_path' => $importPath, 'sbom.cursor_path' => $cursorPath]);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertSee('SBOM scan status')
        ->assertSee('20260101T000000Z');

    File::deleteDirectory($importPath);
    File::deleteDirectory($cursorPath);
});

it('shows static analysis scan status on the operations page', function () {
    $admin = operationsAdmin();

    $importPath = sys_get_temp_dir() . '/static-analysis-status-page-test-' . uniqid();
    $cursorPath = sys_get_temp_dir() . '/static-analysis-status-page-cursor-test-' . uniqid();
    File::ensureDirectoryExists($importPath . '/20260101T000000Z');
    File::put(
        $importPath . '/20260101T000000Z/run.jsonl',
        json_encode(['project' => 'Payments', 'repository' => 'payments-api'], JSON_THROW_ON_ERROR) . "\n",
    );
    config(['static_analysis.import_path' => $importPath, 'static_analysis.cursor_path' => $cursorPath]);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertSee('Static analysis scan status')
        ->assertSee('20260101T000000Z');

    File::deleteDirectory($importPath);
    File::deleteDirectory($cursorPath);
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

it('warns and does not dispatch inventory sync when no inventory-capable provider is enabled', function () {
    Bus::fake();

    app()->forgetInstance(SourceRegistry::class);

    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->call('dispatchSyncInventory')
        ->assertNotified('No enabled Source or Source Control provider can supply inventory. Enable one in Integration Settings first.');

    Bus::assertNotDispatched(SyncInventoryJob::class);
    expect(AuditLog::query()->where('action', 'operations.sync_inventory')->exists())->toBeFalse();
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
        ->assertDontSee('Jobs waiting in the queue')
        ->assertDontSee('Failed jobs needing attention')
        ->assertDontSee('Active source sync processes')
        ->assertDontSee('Registered schedule entries');
});

it('shows all six operations health stats to an admin.queue user', function () {
    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertSee('Reconciliation')
        ->assertSee('Inventory sync')
        ->assertSee('Jobs waiting in the queue')
        ->assertSee('Failed jobs needing attention')
        ->assertSee('Active source sync processes')
        ->assertSee('Registered schedule entries');
});

it('header action dispatches source by form data', function () {
    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->set('selectedSourceId', 'fake')
        ->call('dispatchSelectedSource');

    expect(AuditLog::query()->where('action', 'operations.dispatch_source_fetch')->exists())->toBeTrue();
});

it('header action dispatches tracker by form data', function () {
    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->set('selectedTrackerId', 'fake-tracker')
        ->call('dispatchSelectedTracker');

    expect(AuditLog::query()->where('action', 'operations.dispatch_tracker_refresh')->exists())->toBeTrue();
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
