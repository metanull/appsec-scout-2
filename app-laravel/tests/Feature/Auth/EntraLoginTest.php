<?php

use App\Audit\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use SocialiteProviders\Manager\OAuth2\User as OAuthUser;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function fakeEntraIdToken(string $objectId, array $roles = []): string
{
    $encode = fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

    return $encode(['alg' => 'none']) . '.' . $encode(['oid' => $objectId, 'roles' => $roles]) . '.signature';
}

function fakeEntraUser(string $objectId, string $email, string $name = 'Entra User', array $roles = []): OAuthUser
{
    $user = (new OAuthUser)->map(['id' => $objectId, 'name' => $name, 'email' => $email]);
    $user->setAccessTokenResponseBody(['id_token' => fakeEntraIdToken($objectId, $roles)]);

    return $user;
}

function mockEntraCallback(OAuthUser|Closure $result): void
{
    $provider = Mockery::mock(Provider::class);

    if ($result instanceof Closure) {
        $provider->shouldReceive('user')->andReturnUsing($result);
    } else {
        $provider->shouldReceive('user')->andReturn($result);
    }

    Socialite::shouldReceive('driver')->with('entra')->andReturn($provider);
}

it('returns 404 on both Entra routes when Entra is disabled', function () {
    $this->get('/auth/entra/redirect')->assertNotFound();
    $this->get('/auth/entra/callback')->assertNotFound();
});

it('redirects to Microsoft with the OIDC scopes when Entra is enabled', function () {
    config(['entra.enabled' => true]);

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('scopes')->with(['openid', 'profile', 'email', 'User.Read'])->andReturnSelf();
    $provider->shouldReceive('redirect')->andReturn(redirect()->away('https://login.microsoftonline.com/tenant/oauth2/v2.0/authorize'));
    Socialite::shouldReceive('driver')->with('entra')->andReturn($provider);

    $this->get('/auth/entra/redirect')
        ->assertRedirect('https://login.microsoftonline.com/tenant/oauth2/v2.0/authorize');
});

it('provisions a new federated user from the callback and syncs claim roles', function () {
    config(['entra.enabled' => true]);
    mockEntraCallback(fakeEntraUser('oid-1', 'New.User@Example.com', 'New User', ['Triage']));

    $response = $this->get('/auth/entra/callback?code=fake-code');

    $response->assertRedirect('/');

    $user = User::query()->where('email', 'new.user@example.com')->firstOrFail();

    expect($user->getRawOriginal('password'))->toBeNull()
        ->and($user->entra_object_id)->toBe('oid-1')
        ->and($user->getRoleNames()->all())->toBe(['Triage'])
        ->and(session('entra.authenticated'))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user_sso_provisioned')->where('subject_id', (string) $user->id)->exists())->toBeTrue();

    $this->assertAuthenticatedAs($user);

    $roleSync = AuditLog::query()->where('action', 'user_roles_synced_from_idp')->firstOrFail();
    expect($roleSync->payload_json['before'])->toBe(['Reader'])
        ->and($roleSync->payload_json['after'])->toBe(['Triage']);
});

it('links an existing local user by email and keeps their password', function () {
    config(['entra.enabled' => true]);

    $local = User::factory()->create(['email' => 'local@example.com']);
    $local->syncRoles(['Plan']);

    mockEntraCallback(fakeEntraUser('oid-2', 'local@example.com', 'Local User', ['Plan']));

    $this->get('/auth/entra/callback?code=fake-code')->assertRedirect('/');

    $local->refresh();

    expect($local->entra_object_id)->toBe('oid-2')
        ->and($local->getRawOriginal('password'))->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'user_sso_linked')->where('subject_id', (string) $local->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user_roles_synced_from_idp')->exists())->toBeFalse();

    $this->assertAuthenticatedAs($local);
});

it('re-syncs roles from the claim at every login, ignoring unknown role names', function () {
    config(['entra.enabled' => true]);

    $user = User::factory()->create(['email' => 'known@example.com', 'entra_object_id' => 'oid-3']);
    $user->syncRoles(['Triage']);

    mockEntraCallback(fakeEntraUser('oid-3', 'known@example.com', 'Known User', ['Admin', 'NotARealRole']));

    $this->get('/auth/entra/callback?code=fake-code')->assertRedirect('/');

    expect($user->fresh()->getRoleNames()->all())->toBe(['Admin']);
});

it('clears all roles when the claim carries none', function () {
    config(['entra.enabled' => true]);

    $user = User::factory()->create(['email' => 'norole@example.com', 'entra_object_id' => 'oid-4']);
    $user->syncRoles(['Reader']);

    mockEntraCallback(fakeEntraUser('oid-4', 'norole@example.com', 'No Role', []));

    $this->get('/auth/entra/callback?code=fake-code')->assertRedirect('/');

    expect($user->fresh()->getRoleNames()->all())->toBe([]);
});

it('rejects a disabled user at the callback without starting a session', function () {
    config(['entra.enabled' => true]);

    User::factory()->create([
        'email' => 'disabled@example.com',
        'entra_object_id' => 'oid-5',
        'is_disabled' => true,
    ]);

    mockEntraCallback(fakeEntraUser('oid-5', 'disabled@example.com'));

    $response = $this->get('/auth/entra/callback?code=fake-code');

    $response->assertRedirect('/login');
    $this->assertGuest();
});

it('redirects to the login page when the state is invalid instead of erroring', function () {
    config(['entra.enabled' => true]);
    mockEntraCallback(fn () => throw new InvalidStateException);

    $response = $this->get('/auth/entra/callback?code=fake-code');

    $response->assertRedirect('/login');
    $this->assertGuest();
});

it('redirects to the login page when the callback carries no code', function () {
    config(['entra.enabled' => true]);

    $response = $this->get('/auth/entra/callback?error=access_denied');

    $response->assertRedirect('/login');
    $this->assertGuest();
});
