<?php

use App\Audit\AuditLog;
use App\Filament\Resources\ErrorLogResource;
use App\Filament\Resources\ErrorLogResource\Pages\ListErrorLogs;
use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SoftwareSystemResource;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function errorLogAdmin(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);

    return $user;
}

function errorLogReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

it('renders the error log view page with the full untruncated trace', function () {
    $admin = errorLogAdmin();

    $longTrace = str_repeat('frame line ', 100);

    $log = ErrorLog::query()->create([
        'channel' => 'sync',
        'level' => 'ERROR',
        'message' => 'Sync failed unexpectedly',
        'context_json' => null,
        'trace' => $longTrace,
        'occurred_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(ErrorLogResource::getUrl('view', ['record' => $log]))
        ->assertOk()
        ->assertSee('Sync failed unexpectedly')
        ->assertSee($longTrace);
});

it('gates the error log view page the same as the list page', function () {
    $log = ErrorLog::query()->create([
        'channel' => 'sync',
        'level' => 'ERROR',
        'message' => 'Sync failed unexpectedly',
        'context_json' => null,
        'trace' => 'trace',
        'occurred_at' => now(),
    ]);

    $reader = errorLogReader();
    $this->actingAs($reader);
    expect(ErrorLogResource::canViewAny())->toBeFalse();

    $this->actingAs($reader)
        ->get(ErrorLogResource::getUrl('view', ['record' => $log]))
        ->assertForbidden();

    $admin = errorLogAdmin();
    $this->actingAs($admin);
    expect(ErrorLogResource::canViewAny())->toBeTrue();

    $this->actingAs($admin)
        ->get(ErrorLogResource::getUrl('view', ['record' => $log]))
        ->assertOk();
});

it('lists rows that link to the view page', function () {
    $admin = errorLogAdmin();

    $log = ErrorLog::query()->create([
        'channel' => 'sync',
        'level' => 'ERROR',
        'message' => 'Sync failed unexpectedly',
        'context_json' => null,
        'trace' => 'trace',
        'occurred_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListErrorLogs::class)
        ->assertCanSeeTableRecords([$log]);

    expect(ErrorLogResource::getUrl('view', ['record' => $log]))->toBeString();
});

it('scopes error logs to the selected channel tab', function () {
    $admin = errorLogAdmin();

    $sync = ErrorLog::query()->create([
        'channel' => 'sync', 'level' => 'ERROR', 'message' => 'sync failure', 'trace' => '', 'occurred_at' => now(),
    ]);
    $collection = ErrorLog::query()->create([
        'channel' => 'repository-collection', 'level' => 'ERROR', 'message' => 'collection failure', 'trace' => '', 'occurred_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListErrorLogs::class)
        ->set('activeTab', 'repository-collection')
        ->assertCanSeeTableRecords([$collection])
        ->assertCanNotSeeTableRecords([$sync]);
});

it('shows every error log under the All tab regardless of channel', function () {
    $admin = errorLogAdmin();

    $sync = ErrorLog::query()->create([
        'channel' => 'sync', 'level' => 'ERROR', 'message' => 'sync failure', 'trace' => '', 'occurred_at' => now(),
    ]);
    $collection = ErrorLog::query()->create([
        'channel' => 'repository-collection', 'level' => 'ERROR', 'message' => 'collection failure', 'trace' => '', 'occurred_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListErrorLogs::class)
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$sync, $collection]);
});

it('badges each channel tab with its own error count', function () {
    $admin = errorLogAdmin();

    ErrorLog::query()->create([
        'channel' => 'repository-collection', 'level' => 'ERROR', 'message' => 'one', 'trace' => '', 'occurred_at' => now(),
    ]);
    ErrorLog::query()->create([
        'channel' => 'repository-collection', 'level' => 'ERROR', 'message' => 'two', 'trace' => '', 'occurred_at' => now(),
    ]);
    ErrorLog::query()->create([
        'channel' => 'sync', 'level' => 'ERROR', 'message' => 'three', 'trace' => '', 'occurred_at' => now(),
    ]);

    $tabs = Livewire::actingAs($admin)
        ->test(ListErrorLogs::class)
        ->instance()
        ->getCachedTabs();

    expect($tabs['repository-collection']->getBadge())->toBe('2')
        ->and($tabs['sync']->getBadge())->toBe('1')
        ->and($tabs['static-analysis']->getBadge())->toBe('0');
});

it('filters error logs by the collection run recorded in their context', function () {
    $admin = errorLogAdmin();

    $forThisRun = ErrorLog::query()->create([
        'channel' => 'repository-collection', 'level' => 'ERROR', 'message' => 'this run failed',
        'context_json' => ['run' => 42, 'repository' => 'backend-api'], 'trace' => '', 'occurred_at' => now(),
    ]);
    $forAnotherRun = ErrorLog::query()->create([
        'channel' => 'repository-collection', 'level' => 'ERROR', 'message' => 'another run failed',
        'context_json' => ['run' => 99, 'repository' => 'frontend-app'], 'trace' => '', 'occurred_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListErrorLogs::class)
        ->filterTable('run', ['value' => '42'])
        ->assertCanSeeTableRecords([$forThisRun])
        ->assertCanNotSeeTableRecords([$forAnotherRun]);
});

it('shows the resolved system and container names as links on the view page', function () {
    $admin = errorLogAdmin();

    $system = SoftwareSystem::factory()->create(['name' => 'backend-api']);
    $container = SecurityContainer::factory()->create([
        'software_system_id' => $system->id,
        'name' => 'backend-api-repo',
    ]);

    $log = ErrorLog::query()->create([
        'channel' => 'repository-collection',
        'level' => 'ERROR',
        'message' => 'clone failed',
        'software_system_id' => $system->id,
        'security_container_id' => $container->id,
        'trace' => '',
        'occurred_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(ErrorLogResource::getUrl('view', ['record' => $log]))
        ->assertOk()
        ->assertSee('backend-api')
        ->assertSee('backend-api-repo')
        ->assertSee(SoftwareSystemResource::getUrl('view', ['record' => $system]), escape: false)
        ->assertSee(SecurityContainerResource::getUrl('view', ['record' => $container]), escape: false);
});

it('shows a dash for the system and container on the view page when unresolved', function () {
    $admin = errorLogAdmin();

    $log = ErrorLog::query()->create([
        'channel' => 'sync', 'level' => 'ERROR', 'message' => 'source-wide failure', 'trace' => '', 'occurred_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(ErrorLogResource::getUrl('view', ['record' => $log]))
        ->assertOk()
        ->assertSee('-');
});

it('shows the resolved system and container names in the list table', function () {
    $admin = errorLogAdmin();

    $system = SoftwareSystem::factory()->create(['name' => 'backend-api']);
    $container = SecurityContainer::factory()->create([
        'software_system_id' => $system->id,
        'name' => 'backend-api-repo',
    ]);

    $log = ErrorLog::query()->create([
        'channel' => 'repository-collection',
        'level' => 'ERROR',
        'message' => 'clone failed',
        'software_system_id' => $system->id,
        'security_container_id' => $container->id,
        'trace' => '',
        'occurred_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListErrorLogs::class)
        ->assertCanSeeTableRecords([$log])
        ->assertSee('backend-api')
        ->assertSee('backend-api-repo');
});

it('filters error logs by container', function () {
    $admin = errorLogAdmin();

    $systemA = SoftwareSystem::factory()->create();
    $containerA = SecurityContainer::factory()->create(['software_system_id' => $systemA->id]);
    $systemB = SoftwareSystem::factory()->create();
    $containerB = SecurityContainer::factory()->create(['software_system_id' => $systemB->id]);

    $forA = ErrorLog::query()->create([
        'channel' => 'repository-collection', 'level' => 'ERROR', 'message' => 'a failure',
        'software_system_id' => $systemA->id, 'security_container_id' => $containerA->id,
        'trace' => '', 'occurred_at' => now(),
    ]);
    $forB = ErrorLog::query()->create([
        'channel' => 'repository-collection', 'level' => 'ERROR', 'message' => 'b failure',
        'software_system_id' => $systemB->id, 'security_container_id' => $containerB->id,
        'trace' => '', 'occurred_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListErrorLogs::class)
        ->filterTable('security_container_id', $containerA->id)
        ->assertCanSeeTableRecords([$forA])
        ->assertCanNotSeeTableRecords([$forB]);
});

it('cleans up error logs older than the configured retention window, audits, and notifies', function () {
    $admin = errorLogAdmin();
    config(['logging.error_retain_days' => 30]);

    $old = ErrorLog::query()->create([
        'channel' => 'sync', 'level' => 'ERROR', 'message' => 'old failure', 'trace' => '', 'occurred_at' => now()->subDays(45),
    ]);
    $recent = ErrorLog::query()->create([
        'channel' => 'sync', 'level' => 'ERROR', 'message' => 'recent failure', 'trace' => '', 'occurred_at' => now()->subDays(10),
    ]);

    Livewire::actingAs($admin)
        ->test(ListErrorLogs::class)
        ->callAction('cleanUpNow')
        ->assertNotified('1 error log(s) deleted');

    expect(ErrorLog::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(ErrorLog::query()->whereKey($recent->id)->exists())->toBeTrue();

    $audit = AuditLog::query()->where('action', 'operations.prune_error_logs')->firstOrFail();
    expect($audit->payload_json)->toBe(['deleted' => 1, 'retain_days' => 30]);
});

it('hides the Clean up now action from a user without admin.errors', function () {
    $reader = errorLogReader();

    DB::table('error_logs')->insert([
        'channel' => 'sync', 'level' => 'ERROR', 'message' => 'failure', 'trace' => '', 'occurred_at' => now(),
    ]);

    $this->actingAs($reader)
        ->get(ErrorLogResource::getUrl('index'))
        ->assertForbidden();
});

it('filters error logs by an occurred_at date range, including an upper bound', function () {
    $admin = errorLogAdmin();

    $early = ErrorLog::query()->create([
        'channel' => 'sync', 'level' => 'ERROR', 'message' => 'early failure', 'trace' => '', 'occurred_at' => '2026-01-05',
    ]);
    $late = ErrorLog::query()->create([
        'channel' => 'sync', 'level' => 'ERROR', 'message' => 'late failure', 'trace' => '', 'occurred_at' => '2026-01-25',
    ]);

    Livewire::actingAs($admin)
        ->test(ListErrorLogs::class)
        ->filterTable('occurred_at_from', ['occurred_at_from' => '2026-01-10'])
        ->assertCanSeeTableRecords([$late])
        ->assertCanNotSeeTableRecords([$early])
        ->removeTableFilter('occurred_at_from')
        ->filterTable('occurred_at_until', ['occurred_at_until' => '2026-01-10'])
        ->assertCanSeeTableRecords([$early])
        ->assertCanNotSeeTableRecords([$late]);
});
