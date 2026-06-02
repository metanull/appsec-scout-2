<?php

use App\Audit\AuditLog;
use App\Audit\Recorder;
use App\Credentials\Credential;
use App\Credentials\Vault;
use App\Models\User;
use App\Sync\CredentialResolver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

it('stores value encrypted in the database', function () {
    $vault = new Vault(new Recorder, app(CredentialResolver::class));

    $vault->set('azdo.pat', null, 'super-secret-token');

    $raw = DB::table('credentials')
        ->where('integration_key', 'azdo.pat')
        ->value('value');

    expect($raw)->not()->toBe('super-secret-token');
    expect(Crypt::decryptString($raw))->toBe('super-secret-token');
});

it('round-trips get after set', function () {
    $vault = new Vault(new Recorder, app(CredentialResolver::class));

    $vault->set('azdo.pat', null, 'my-pat-value');

    expect($vault->get('azdo.pat', null))->toBe('my-pat-value');
});

it('returns null for missing credential', function () {
    $vault = new Vault(new Recorder, app(CredentialResolver::class));

    expect($vault->get('missing.key', null))->toBeNull();
});

it('returns null when credential payload cannot be decrypted', function () {
    DB::table('credentials')->insert([
        'integration_key' => 'azdo.pat',
        'owner_user_id' => null,
        'value' => 'invalid-payload',
    ]);

    expect(vault()->get('azdo.pat', null))->toBeNull();
});

it('writes audit row on set with value redacted', function () {
    vault()->set('azdo.pat', null, 'secret');

    $log = AuditLog::where('action', 'credential_change')->first();
    expect($log)->not()->toBeNull();

    $payload = $log->payload_json;
    expect($payload)->toHaveKey('actor')
        ->and($payload)->not()->toHaveKey('value')
        ->and($payload)->not()->toContain('secret');
});

it('updates existing credential on re-set', function () {
    $vault = vault();

    $vault->set('azdo.pat', null, 'first-value');
    $vault->set('azdo.pat', null, 'second-value');

    expect(Credential::count())->toBe(1)
        ->and($vault->get('azdo.pat', null))->toBe('second-value');
});

it('test probe returns ok on success', function () {
    $vault = vault();

    $vault->set('azdo.pat', null, 'valid-token');
    $result = $vault->test('azdo.pat', null, fn (string $v) => true);

    expect($result->ok)->toBeTrue()
        ->and($result->missing)->toBeFalse();

    $credential = Credential::where('integration_key', 'azdo.pat')->first();
    expect($credential->last_tested_ok)->toBeTrue();
});

it('test probe returns fail on exception', function () {
    $vault = vault();

    $vault->set('azdo.pat', null, 'bad-token');
    $result = $vault->test('azdo.pat', null, function () {
        throw new RuntimeException('Auth failed');
    });

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe('Auth failed');
});

it('test returns missing when credential does not exist', function () {
    $result = vault()->test('nonexistent.key', null, fn (string $v) => null);

    expect($result->missing)->toBeTrue();
});

it('system and user credentials are stored separately', function () {
    $user = User::factory()->create();
    $vault = vault();

    $vault->set('azdo.pat', null, 'system-token');
    $vault->set('azdo.pat', $user->id, 'user-token');

    expect($vault->get('azdo.pat', null))->toBe('system-token')
        ->and($vault->get('azdo.pat', $user->id))->toBe('user-token');
});

it('prefers the authenticated user credential when resolving without an explicit owner', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $vault = vault();

    $vault->set('azdo.pat', null, 'system-token');
    $vault->set('azdo.pat', $user->id, 'user-token');

    expect($vault->get('azdo.pat', null))->toBe('user-token');
});

it('supports strict owner scoping for integration tests', function () {
    $user = User::factory()->create();
    $vault = vault();

    $vault->set('azdo.pat', null, 'system-token');
    $vault->set('azdo.pat', $user->id, 'user-token');

    $resolved = $vault->runAsOwner(null, fn (): ?string => $vault->get('azdo.pat', null));

    expect($resolved)->toBe('system-token');
});

function vault(): Vault
{
    return new Vault(new Recorder, app(CredentialResolver::class));
}
