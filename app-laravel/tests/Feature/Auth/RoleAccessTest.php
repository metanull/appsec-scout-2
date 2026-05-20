<?php

use App\Filament\Resources\SoftwareSystemLinkResource;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('new user is automatically assigned the Reader role', function () {
    $user = User::factory()->create();

    expect($user->hasRole('Reader'))->toBeTrue();
});

it('new user does not get Reader role when none exists yet', function () {
    Role::where('name', 'Reader')->delete();

    $user = User::factory()->create();

    expect($user->roles->count())->toBe(0);
});

it('user with explicit role does not get overwritten with Reader', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Admin']);

    expect($user->hasRole('Admin'))->toBeTrue()
        ->and($user->hasRole('Reader'))->toBeFalse();
});

it('Reader role user cannot view audit log resource', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $this->actingAs($user);

    expect($user->can('admin.audit'))->toBeFalse();
});

it('Admin role user can view audit log resource', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);
    $this->actingAs($user);

    expect($user->can('admin.audit'))->toBeTrue();
});

it('Reader role user cannot view error log resource', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $this->actingAs($user);

    expect($user->can('admin.errors'))->toBeFalse();
});

it('Admin role user can view error log resource', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);
    $this->actingAs($user);

    expect($user->can('admin.errors'))->toBeTrue();
});

it('Reader role user cannot view system links resource', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $this->actingAs($user);

    expect(SoftwareSystemLinkResource::canViewAny())->toBeFalse();
});

it('Admin role user can view system links resource', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);
    $this->actingAs($user);

    expect(SoftwareSystemLinkResource::canViewAny())->toBeTrue();
});
