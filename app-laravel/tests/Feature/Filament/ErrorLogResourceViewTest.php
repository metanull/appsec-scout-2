<?php

use App\Filament\Resources\ErrorLogResource;
use App\Filament\Resources\ErrorLogResource\Pages\ListErrorLogs;
use App\Models\ErrorLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
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
