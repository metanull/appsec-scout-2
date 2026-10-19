<?php

use App\Credentials\Vault;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

it('prints a configured system credential value to stdout', function () {
    app(Vault::class)->set('azdo.pat', null, 'system-azdo-pat-value');

    $exitCode = Artisan::call('credentials:system:get', ['key' => 'azdo.pat']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toBe('system-azdo-pat-value');
});

it('prints a configured source control system credential value to stdout', function () {
    app(Vault::class)->set('azdo-repos.pat', null, 'system-azdo-repos-pat-value');

    $exitCode = Artisan::call('credentials:system:get', ['key' => 'azdo-repos.pat']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toBe('system-azdo-repos-pat-value');
});

it('fails when the credential key is unknown', function () {
    $exitCode = Artisan::call('credentials:system:get', ['key' => 'not.a.real.key']);

    expect($exitCode)->not->toBe(0);
});

it('fails when a known key has no configured value', function () {
    $exitCode = Artisan::call('credentials:system:get', ['key' => 'github.token']);

    expect($exitCode)->not->toBe(0);
});

it('prints the system value, never a user-owned one, for the same key', function () {
    $user = User::factory()->create();
    app(Vault::class)->set('azdo-repos.pat', $user->id, 'user-owned-value');
    app(Vault::class)->set('azdo-repos.pat', null, 'system-value');

    $exitCode = Artisan::call('credentials:system:get', ['key' => 'azdo-repos.pat']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toBe('system-value');
});
