<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('new user without app MFA is redirected to required setup page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/');

    $response->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('user with app MFA configured can access the dashboard', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1', 'code-2'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole('Admin');
    $this->actingAs($user);

    $response = $this->get('/');

    $response->assertSuccessful();
});

it('unauthenticated user is redirected to login', function () {
    $response = $this->get('/');

    $response->assertRedirect();
});

it('required MFA setup page is accessible when authenticated without app MFA', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());

    $response->assertSuccessful();
});

it('required MFA setup page redirects enrolled user away', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1', 'code-2'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $this->actingAs($user);

    $response = $this->get(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());

    $response->assertRedirect('/profile');
});
