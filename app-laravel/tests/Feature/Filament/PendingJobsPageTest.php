<?php

use App\Audit\AuditLog;
use App\Filament\Pages\PendingJobsPage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    config(['queue.default' => 'database']);
});

function pendingJobsAdmin(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);

    return $user;
}

function pendingJobsReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

function insertPendingJob(string $queue, string $payload): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => $payload,
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
}

it('denies access to a user without admin.queue and allows an admin', function () {
    $reader = pendingJobsReader();
    $this->actingAs($reader);
    expect(PendingJobsPage::canAccess())->toBeFalse();

    $admin = pendingJobsAdmin();
    $this->actingAs($admin);
    expect(PendingJobsPage::canAccess())->toBeTrue();

    $this->actingAs($reader)->get(PendingJobsPage::getUrl())->assertForbidden();
    $this->actingAs($admin)->get(PendingJobsPage::getUrl())->assertOk();
});

it('lists pending jobs with the parsed job name and queue', function () {
    $admin = pendingJobsAdmin();

    insertPendingJob('default', '{"displayName":"App\\\\Jobs\\\\ExampleJob"}');
    insertPendingJob('repository-collection', '{"displayName":"App\\\\SourceControl\\\\Collection\\\\CollectRepositoryJob"}');

    Livewire::actingAs($admin)
        ->test(PendingJobsPage::class)
        ->assertSee('ExampleJob')
        ->assertSee('CollectRepositoryJob')
        ->assertSee('repository-collection');
});

it('filters pending jobs by queue', function () {
    $admin = pendingJobsAdmin();

    insertPendingJob('default', '{"displayName":"App\\\\Jobs\\\\OnDefault"}');
    insertPendingJob('repository-collection', '{"displayName":"App\\\\Jobs\\\\OnCollection"}');

    Livewire::actingAs($admin)
        ->test(PendingJobsPage::class)
        ->filterTable('queue', 'repository-collection')
        ->assertSee('OnCollection')
        ->assertDontSee('OnDefault');
});

it('drops exactly the targeted pending job and records an audit action', function () {
    $admin = pendingJobsAdmin();

    $keepPayload = '{"displayName":"App\\\\Jobs\\\\Keep"}';
    $dropPayload = '{"displayName":"App\\\\Jobs\\\\Drop"}';
    insertPendingJob('default', $keepPayload);
    insertPendingJob('default', $dropPayload);

    Livewire::actingAs($admin)
        ->test(PendingJobsPage::class)
        ->callTableAction('drop', PendingJobsPage::jobKey('default', $dropPayload));

    expect(DB::table('jobs')->where('payload', $dropPayload)->exists())->toBeFalse()
        ->and(DB::table('jobs')->where('payload', $keepPayload)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'operations.drop_pending_job')->exists())->toBeTrue();
});

it('paginates pending jobs at 25 per page', function () {
    $admin = pendingJobsAdmin();

    foreach (range(1, 30) as $i) {
        insertPendingJob('default', sprintf('{"displayName":"App\\\\Jobs\\\\Job%02d"}', $i));
    }

    $component = Livewire::actingAs($admin)->test(PendingJobsPage::class);

    $firstPage = $component->instance()->getTableRecords();
    expect($firstPage->count())->toBe(25)
        ->and($firstPage->total())->toBe(30);

    $component->call('gotoPage', 2);

    $secondPage = $component->instance()->getTableRecords();
    expect($secondPage->count())->toBe(5)
        ->and($secondPage->total())->toBe(30);
});

it('keeps the queue filter applied when navigating to a later page', function () {
    $admin = pendingJobsAdmin();

    foreach (range(1, 30) as $i) {
        insertPendingJob('default', sprintf('{"displayName":"App\\\\Jobs\\\\Job%02d"}', $i));
    }
    foreach (range(1, 5) as $i) {
        insertPendingJob('repository-collection', sprintf('{"displayName":"App\\\\Jobs\\\\Collect%02d"}', $i));
    }

    $component = Livewire::actingAs($admin)
        ->test(PendingJobsPage::class)
        ->filterTable('queue', 'default');

    expect($component->instance()->getTableRecords()->total())->toBe(30);

    $component->call('gotoPage', 2);

    $secondPage = $component->instance()->getTableRecords();
    expect($secondPage->count())->toBe(5)
        ->and($secondPage->total())->toBe(30);
});
