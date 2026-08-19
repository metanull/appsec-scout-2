<?php

use App\Audit\AuditLog;
use App\Filament\Resources\FailedJobResource;
use App\Filament\Resources\FailedJobResource\Pages\ListFailedJobs;
use App\Filament\Resources\FailedJobResource\Pages\ViewFailedJob;
use App\Filament\Support\JobPayloadInspector;
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

it('renders the failed job view page with the exception and payload shown in full', function () {
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
        ->assertDontSee('[redacted]')
        ->assertSeeText('my-secret-exception-value')
        ->assertSeeText('my-secret');
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

    expect(JobPayloadInspector::exceptionPreview($exception))
        ->toBe('Database value exceeded security_events.version_control_url. Run migrations, then retry or forget this failed job.');
});

it('retries a failed job from the view page header action and redirects to the index', function () {
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
        ->test(ViewFailedJob::class, ['record' => $record->getKey()])
        ->callAction('retry')
        ->assertRedirect(FailedJobResource::getUrl('index'));

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(DB::table('jobs')->where('queue', 'default')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'operations.retry_failed_job')->exists())->toBeTrue();
});

it('forgets a failed job from the view page header action and redirects to the index', function () {
    $admin = failedJobAdmin();

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

    Livewire::actingAs($admin)
        ->test(ViewFailedJob::class, ['record' => $record->getKey()])
        ->callAction('forget')
        ->assertRedirect(FailedJobResource::getUrl('index'));

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'operations.forget_failed_job')->exists())->toBeTrue();
});

it('bulk-retries selected failed jobs, re-pushing each payload and clearing them from the list', function () {
    $admin = failedJobAdmin();

    $uuidA = (string) str()->uuid();
    $uuidB = (string) str()->uuid();
    DB::table('failed_jobs')->insert([
        ['uuid' => $uuidA, 'connection' => 'database', 'queue' => 'default', 'payload' => '{"job":"A"}', 'exception' => 'boom', 'failed_at' => now()],
        ['uuid' => $uuidB, 'connection' => 'database', 'queue' => 'default', 'payload' => '{"job":"B"}', 'exception' => 'boom', 'failed_at' => now()],
    ]);
    $records = FailedJob::whereIn('uuid', [$uuidA, $uuidB])->get();

    Livewire::actingAs($admin)
        ->test(ListFailedJobs::class)
        ->callTableBulkAction('retrySelected', $records);

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'operations.retry_failed_job')->count())->toBe(2);
});

it('bulk-forgets selected failed jobs', function () {
    $admin = failedJobAdmin();

    $uuidA = (string) str()->uuid();
    $uuidB = (string) str()->uuid();
    DB::table('failed_jobs')->insert([
        ['uuid' => $uuidA, 'connection' => 'database', 'queue' => 'default', 'payload' => '{"job":"A"}', 'exception' => 'boom', 'failed_at' => now()],
        ['uuid' => $uuidB, 'connection' => 'database', 'queue' => 'default', 'payload' => '{"job":"B"}', 'exception' => 'boom', 'failed_at' => now()],
    ]);
    $records = FailedJob::whereIn('uuid', [$uuidA, $uuidB])->get();

    Livewire::actingAs($admin)
        ->test(ListFailedJobs::class)
        ->callTableBulkAction('forgetSelected', $records);

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'operations.forget_failed_job')->count())->toBe(2);
});

it('does not abort a bulk retry when one selected job was already resolved by another operator', function () {
    $admin = failedJobAdmin();

    $uuidA = (string) str()->uuid();
    $uuidB = (string) str()->uuid();
    DB::table('failed_jobs')->insert([
        ['uuid' => $uuidA, 'connection' => 'database', 'queue' => 'default', 'payload' => '{"job":"A"}', 'exception' => 'boom', 'failed_at' => now()],
        ['uuid' => $uuidB, 'connection' => 'database', 'queue' => 'default', 'payload' => '{"job":"B"}', 'exception' => 'boom', 'failed_at' => now()],
    ]);
    $records = FailedJob::whereIn('uuid', [$uuidA, $uuidB])->get();

    DB::table('failed_jobs')->where('uuid', $uuidA)->delete();

    Livewire::actingAs($admin)
        ->test(ListFailedJobs::class)
        ->callTableBulkAction('retrySelected', $records);

    expect(DB::table('failed_jobs')->where('uuid', $uuidB)->exists())->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'operations.retry_failed_job')->count())->toBe(1);
});

it('filters failed jobs by a failed_at date range, including an upper bound', function () {
    $admin = failedJobAdmin();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database', 'queue' => 'default', 'payload' => '{"job":"Early"}', 'exception' => 'boom',
        'failed_at' => '2026-01-05 00:00:00',
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database', 'queue' => 'default', 'payload' => '{"job":"Late"}', 'exception' => 'boom',
        'failed_at' => '2026-01-25 00:00:00',
    ]);
    $early = FailedJob::where('payload', 'like', '%Early%')->firstOrFail();
    $late = FailedJob::where('payload', 'like', '%Late%')->firstOrFail();

    Livewire::actingAs($admin)
        ->test(ListFailedJobs::class)
        ->filterTable('failed_at_from', ['failed_at_from' => '2026-01-10'])
        ->assertCanSeeTableRecords([$late])
        ->assertCanNotSeeTableRecords([$early])
        ->removeTableFilter('failed_at_from')
        ->filterTable('failed_at_until', ['failed_at_until' => '2026-01-10'])
        ->assertCanSeeTableRecords([$early])
        ->assertCanNotSeeTableRecords([$late]);
});

it('renders the failed job payload in full without masking', function () {
    $payload = json_encode(['job' => 'Example', 'token' => 'secret-token'], JSON_THROW_ON_ERROR);

    expect(FailedJobResource::payloadFull($payload))
        ->toContain('secret-token')
        ->not->toContain('[redacted]');
});
