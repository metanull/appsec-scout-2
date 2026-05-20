<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('new user without 2FA is redirected to two-factor setup', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/');

    $response->assertRedirectToRoute('two-factor.setup');
});

it('user with 2FA secret but unconfirmed is redirected to two-factor setup', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => null,
    ]);
    $this->actingAs($user);

    $response = $this->get('/');

    $response->assertRedirectToRoute('two-factor.setup');
});

it('user with fully enrolled 2FA can access the dashboard', function () {
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

it('two-factor setup page is accessible when authenticated without 2FA', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('two-factor.setup'));

    $response->assertSuccessful();
});

it('setup page auto-enables 2FA and shows QR code', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('two-factor.setup'));

    $response->assertSuccessful();
    $user->refresh();
    expect($user->two_factor_secret)->not()->toBeNull();
});

it('setup page redirects enrolled user away', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1', 'code-2'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $this->actingAs($user);

    $response = $this->get(route('two-factor.setup'));

    $response->assertRedirect('/');
});
