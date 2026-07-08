<?php

use App\Credentials\Vault;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

it('prints any system-scoped vault value to stdout, bypassing the source/tracker allowlist', function () {
    app(Vault::class)->set('dependencytrack.adminPassword', null, 'super-secret-password');

    $exitCode = Artisan::call('vault:get', ['key' => 'dependencytrack.adminPassword']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toBe('super-secret-password');
});

it('fails when the vault key is not configured', function () {
    $exitCode = Artisan::call('vault:get', ['key' => 'not.configured.key']);

    expect($exitCode)->not->toBe(0);
});

it('does not read user-owned credentials', function () {
    $user = User::factory()->create();
    app(Vault::class)->set('azdo.pat', $user->id, 'user-owned-value');

    $exitCode = Artisan::call('vault:get', ['key' => 'azdo.pat']);

    expect($exitCode)->not->toBe(0);
});
