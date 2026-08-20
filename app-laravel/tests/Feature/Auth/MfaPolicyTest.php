<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('waives the TOTP wall for an Entra-authenticated session', function () {
    $user = User::factory()->create([
        'email' => 'federated@example.com',
        'password' => null,
        'entra_object_id' => 'oid-mfa-1',
    ]);
    $user->assignRole('Admin');

    $this->actingAs($user)
        ->withSession(['entra.authenticated' => true])
        ->get('/')
        ->assertSuccessful();
});

it('still walls a password-authenticated session without TOTP, even with Entra enabled', function () {
    config(['entra.enabled' => true]);

    $user = User::factory()->create();
    $user->assignRole('Admin');

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('still walls a linked user with a password when their session is not Entra-authenticated', function () {
    // A linked account keeps its password; only the Entra-authenticated
    // session gets the waiver, never the user record itself.
    $user = User::factory()->create(['entra_object_id' => 'oid-mfa-2']);
    $user->assignRole('Admin');

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('leaves TOTP-enrolled local users unaffected', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole('Admin');

    $this->actingAs($user)
        ->get('/')
        ->assertSuccessful();
});
