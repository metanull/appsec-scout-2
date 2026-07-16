<?php

use App\Credentials\Credential;
use App\Models\User;
use App\Sync\CredentialResolver;

it('resolves the system credential for a null owner', function () {
    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => null, 'value' => 'system-token']);

    expect(app(CredentialResolver::class)->exact('github.token', null)?->value)->toBe('system-token');
});

it('resolves a specific users credential for that owner id', function () {
    $user = User::factory()->create();

    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => null, 'value' => 'system-token']);
    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => $user->id, 'value' => 'user-token']);

    expect(app(CredentialResolver::class)->exact('github.token', $user->id)?->value)->toBe('user-token');
});

it('does not return another users credential when resolving the system owner', function () {
    $otherUser = User::factory()->create();

    Credential::query()->create(['integration_key' => 'github.token', 'owner_user_id' => $otherUser->id, 'value' => 'user-token']);

    expect(app(CredentialResolver::class)->exact('github.token', null))->toBeNull();
});
