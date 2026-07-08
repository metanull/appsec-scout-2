<?php

use App\Credentials\Vault;
use Illuminate\Support\Facades\Artisan;

it('prints a configured system credential value to stdout', function () {
    app(Vault::class)->set('azdo.pat', null, 'system-azdo-pat-value');

    $exitCode = Artisan::call('credentials:system:get', ['key' => 'azdo.pat']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toBe('system-azdo-pat-value');
});

it('fails when the credential key is unknown', function () {
    $exitCode = Artisan::call('credentials:system:get', ['key' => 'not.a.real.key']);

    expect($exitCode)->not->toBe(0);
});

it('fails when a known key has no configured value', function () {
    $exitCode = Artisan::call('credentials:system:get', ['key' => 'github.token']);

    expect($exitCode)->not->toBe(0);
});
