<?php

use App\Credentials\Credential;
use App\Models\User;
use App\Sync\CredentialResolver;

it('prefers the authenticated users credential over system credentials', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => null, 'value' => 'system-token']);
    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => $user->id, 'value' => 'user-token']);

    expect(app(CredentialResolver::class)->resolve('github.token')?->value)->toBe('user-token');
});

it('uses the system credential for background resolution', function () {
    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => null, 'value' => 'system-token']);

    expect(app(CredentialResolver::class)->resolve('github.token')?->value)->toBe('system-token');
});

it('does not use another users credential for background resolution', function () {
    $serviceUser = User::factory()->create();

    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => $serviceUser->id, 'value' => 'service-token']);

    expect(app(CredentialResolver::class)->resolve('github.token'))->toBeNull();
});
