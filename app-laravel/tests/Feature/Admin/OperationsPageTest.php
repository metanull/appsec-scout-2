<?php

use App\Audit\AuditLog;
use App\Filament\Pages\OperationsPage;
use App\Jobs\UpdateTrivyDbJob;
use App\Models\ErrorLog;
use App\Models\FailedJob;
use App\Models\SyncRun;
use App\Models\User;
use App\Sources\Registry as SourceRegistry;
use App\Sync\FetchSourceJob;
use App\Trackers\ReconcileAllJob;
use App\Trackers\RefreshWorkItemsJob;
use App\Trackers\Registry;
use Database\Seeders\RolePermissionSeeder;
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
        ->assertSee('Failed jobs')
        ->assertSee('Sync failed');

    $page = Livewire::actingAs($admin)->test(OperationsPage::class)->instance();

    expect($page->queuedJobCount())->toBe(1)
        ->and($page->failedJobCount())->toBe(1)
        ->and($page->recentFailedJobs()[0]['payload_preview'])->not->toContain('secret-token')
        ->and($page->recentFailedJobs()[0]['exception_preview'])->toBe('Database value exceeded security_events.version_control_url. Run migrations, then retry or forget this failed job.');
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
        ->call('dispatchDueIntegrationsNow')
        ->call('dispatchSelectedSource')
        ->call('dispatchSelectedTracker')
        ->call('updateTrivyDbNow');

    Bus::assertDispatched(FetchSourceJob::class);
    Bus::assertDispatched(RefreshWorkItemsJob::class);
    Bus::assertDispatched(UpdateTrivyDbJob::class);

    expect(AuditLog::query()->where('action', 'operations.dispatch_due_integrations')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'operations.dispatch_source_fetch')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'operations.dispatch_tracker_refresh')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'operations.update_trivy_db')->exists())->toBeTrue();
});

it('retries and forgets failed jobs', function () {
    $admin = operationsAdmin();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Example","displayName":"ExampleJob"}',
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    $failedJobUuid = (string) DB::table('failed_jobs')->value('uuid');

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->call('retryFailedJob', $failedJobUuid);

    expect(DB::table('failed_jobs')->where('uuid', $failedJobUuid)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'operations.retry_failed_job')->exists())->toBeTrue();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Example"}',
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    $failedJobUuid = (string) DB::table('failed_jobs')->value('uuid');

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->call('forgetFailedJob', $failedJobUuid);

    expect(DB::table('failed_jobs')->where('uuid', $failedJobUuid)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'operations.forget_failed_job')->exists())->toBeTrue();
});

it('header actions render for admin', function () {
    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertActionExists('dispatchDueIntegrations')
        ->assertActionExists('fetchSource')
        ->assertActionExists('refreshTracker');
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

it('failed jobs table renders with searchable columns and filters', function () {
    $admin = operationsAdmin();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Example","displayName":"ExampleJob","token":"secret-token"}',
        'exception' => 'RuntimeException: something went wrong',
        'failed_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->assertTableColumnExists('failed_at')
        ->assertTableColumnExists('queue')
        ->assertTableColumnExists('job')
        ->assertTableColumnExists('exception_summary');
});

it('table retry and forget actions work and record audit', function () {
    $admin = operationsAdmin();

    $uuid = (string) str()->uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Example","displayName":"ExampleJob"}',
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    $record = FailedJob::where('uuid', $uuid)->firstOrFail();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->callTableAction('retry', $record);

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'operations.retry_failed_job')->exists())->toBeTrue();

    $uuid2 = (string) str()->uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid2,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Example"}',
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    $record2 = FailedJob::where('uuid', $uuid2)->firstOrFail();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->callTableAction('forget', $record2);

    expect(DB::table('failed_jobs')->where('uuid', $uuid2)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'operations.forget_failed_job')->exists())->toBeTrue();
});

it('details table action modal fills exception and payload', function () {
    $admin = operationsAdmin();

    $uuid = (string) str()->uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Example","token":"my-secret"}',
        'exception' => 'authorization: bearer secret-value',
        'failed_at' => now(),
    ]);

    $record = FailedJob::where('uuid', $uuid)->firstOrFail();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->mountTableAction('details', $record)
        ->assertTableActionDataSet(fn (array $data) => str_contains($data['exception'] ?? '', '***')
            && ! str_contains($data['exception'] ?? '', 'secret-value')
            && ! str_contains($data['payload'] ?? '', 'my-secret'));
});

function bindFakeOperationsIntegrations(): void
{
    config([
        'integration_settings.fake.enabled' => true,
        'integration_settings.fake.interval_minutes' => 1,
        'integration_settings.fake-tracker.enabled' => false,
    ]);

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
