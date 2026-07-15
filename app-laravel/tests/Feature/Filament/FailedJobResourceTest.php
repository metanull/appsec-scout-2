<?php

use App\Audit\AuditLog;
use App\Filament\Resources\FailedJobResource;
use App\Filament\Resources\FailedJobResource\Pages\ListFailedJobs;
use App\Models\FailedJob;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function failedJobAdmin(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);

    return $user;
}

function failedJobReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

it('grants access to an admin.queue user and denies a reader', function () {
    $admin = failedJobAdmin();
    $this->actingAs($admin);
    expect(FailedJobResource::canViewAny())->toBeTrue();

    $reader = failedJobReader();
    $this->actingAs($reader);
    expect(FailedJobResource::canViewAny())->toBeFalse();
});

it('lists failed jobs with searchable columns for an admin.queue user', function () {
    $admin = failedJobAdmin();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Example","displayName":"ExampleJob","token":"secret-token"}',
        'exception' => 'RuntimeException: something went wrong',
        'failed_at' => now(),
    ]);

    $record = FailedJob::query()->first();

    Livewire::actingAs($admin)
        ->test(ListFailedJobs::class)
        ->assertCanSeeTableRecords([$record])
        ->assertTableColumnExists('failed_at')
        ->assertTableColumnExists('queue')
        ->assertTableColumnExists('job')
        ->assertTableColumnExists('exception_summary')
        ->assertSee('ExampleJob');
});

it('table retry and forget actions work and record the same audit actions as before', function () {
    $admin = failedJobAdmin();

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
        ->test(ListFailedJobs::class)
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
        ->test(ListFailedJobs::class)
        ->callTableAction('forget', $record2);

    expect(DB::table('failed_jobs')->where('uuid', $uuid2)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'operations.forget_failed_job')->exists())->toBeTrue();
});

it('renders the failed job view page with redacted exception and payload', function () {
    $admin = failedJobAdmin();

    $uuid = (string) str()->uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Example","token":"my-secret"}',
        'exception' => 'Authorization failed: token=my-secret-exception-value',
        'failed_at' => now(),
    ]);

    $record = FailedJob::where('uuid', $uuid)->firstOrFail();

    $this->actingAs($admin)
        ->get(FailedJobResource::getUrl('view', ['record' => $record]))
        ->assertOk()
        ->assertSee('[redacted]', false)
        ->assertDontSee('my-secret-exception-value')
        ->assertDontSee('my-secret');
});

it('denies the view page to a user without admin.queue', function () {
    $reader = failedJobReader();

    $uuid = (string) str()->uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Example"}',
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    $record = FailedJob::where('uuid', $uuid)->firstOrFail();

    $this->actingAs($reader)
        ->get(FailedJobResource::getUrl('view', ['record' => $record]))
        ->assertForbidden();
});

it('summarizes a failed job exception for a truncated database column error', function () {
    $exception = "PDOException: SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'version_control_url' at row 1 insert into `security_events` values secret-token";

    expect(FailedJobResource::exceptionPreview($exception))
        ->toBe('Database value exceeded security_events.version_control_url. Run migrations, then retry or forget this failed job.');
});

it('redacts sensitive keys in the failed job payload', function () {
    $payload = json_encode(['job' => 'Example', 'token' => 'secret-token'], JSON_THROW_ON_ERROR);

    expect(FailedJobResource::payloadFull($payload))
        ->not->toContain('secret-token')
        ->toContain('[redacted]');
});
