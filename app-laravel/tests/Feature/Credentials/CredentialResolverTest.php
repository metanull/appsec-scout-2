<?php

use App\Credentials\Credential;
use App\Models\User;
use App\Sync\CredentialResolver;

it('prefers the authenticated users credential over system and service user values', function () {
    $user = User::factory()->create();
    $serviceUser = User::factory()->create();
    $this->actingAs($user);

    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => null, 'value' => 'system-token']);
    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => $serviceUser->id, 'value' => 'service-token']);
    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => $user->id, 'value' => 'user-token']);

    config(['integration_settings.service_user_id' => $serviceUser->id]);

    expect(app(CredentialResolver::class)->resolve('github.token')?->value)->toBe('user-token');
});

it('prefers the configured integration service user for background resolution', function () {
    $serviceUser = User::factory()->create();

    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => null, 'value' => 'system-token']);
    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => $serviceUser->id, 'value' => 'service-token']);

    config(['integration_settings.service_user_id' => $serviceUser->id]);

    expect(app(CredentialResolver::class)->resolve('github.token')?->value)->toBe('service-token');
});

it('falls back to the system credential when no service user is configured for the integration', function () {
    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => null, 'value' => 'system-token']);

    config(['integration_settings.service_user_id' => null]);

    expect(app(CredentialResolver::class)->resolve('github.token')?->value)->toBe('system-token');
});

it('falls back to the configured service user when no system credential exists', function () {
    $serviceUser = User::factory()->create();

    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => $serviceUser->id, 'value' => 'service-token']);

    config(['integration_settings.service_user_id' => $serviceUser->id]);

    expect(app(CredentialResolver::class)->resolve('github.token')?->value)->toBe('service-token');
});
