<?php

use App\Credentials\Vault;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

it('lists system-scoped vault keys sorted, without values', function () {
    $vault = app(Vault::class);
    $vault->set('dependencytrack.adminPassword', null, 'super-secret-password');
    $vault->set('azdo.pat', null, 'a-pat-value');

    $exitCode = Artisan::call('vault:list');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('dependencytrack.adminPassword')
        ->and($output)->toContain('azdo.pat')
        ->and($output)->not->toContain('super-secret-password')
        ->and($output)->not->toContain('a-pat-value');

    $keys = array_values(array_filter(explode("\n", $output)));
    expect($keys)->toBe(collect($keys)->sort()->values()->all());
});

it('excludes user-owned credentials', function () {
    $user = User::factory()->create();
    app(Vault::class)->set('azdo.pat', $user->id, 'user-owned-value');

    $exitCode = Artisan::call('vault:list');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->not->toContain('azdo.pat');
});
