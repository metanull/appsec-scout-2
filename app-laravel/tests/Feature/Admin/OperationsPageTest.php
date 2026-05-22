<?php

use App\Audit\AuditLog;
use App\Filament\Pages\OperationsPage;
use App\Models\ErrorLog;
use App\Models\SyncRun;
use App\Models\User;
use App\Sources\Registry as SourceRegistry;
use App\Trackers\Registry;
use Database\Seeders\RolePermissionSeeder;
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

    $admin = operationsAdmin();
    $this->actingAs($admin);

    expect(OperationsPage::canAccess())->toBeTrue();
});

it('shows queue failed-job sync-run and error counts', function () {
    $admin = operationsAdmin();

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
        ->assertSee('Sync failed')
        ->assertSee('Unknown job');

    $page = Livewire::actingAs($admin)->test(OperationsPage::class)->instance();

    expect($page->queuedJobCount())->toBe(1)
        ->and($page->failedJobCount())->toBe(1)
        ->and($page->recentFailedJobs()[0]['payload_preview'])->not->toContain('secret-token')
        ->and($page->recentFailedJobs()[0]['exception_preview'])->toBe('Database value exceeded security_events.version_control_url. Run migrations, then retry or forget this failed job.');
});

it('queues supported operational actions and records audit rows', function () {
    $admin = operationsAdmin();

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->set('selectedSourceId', 'fake')
        ->set('selectedTrackerId', 'fake-tracker')
        ->call('dispatchDueIntegrationsNow')
        ->call('dispatchSelectedSource')
        ->call('dispatchSelectedTracker')
        ->call('updateTrivyDbNow');

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
