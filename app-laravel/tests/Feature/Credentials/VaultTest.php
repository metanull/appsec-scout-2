<?php

use App\Audit\AuditLog;
use App\Audit\Recorder;
use App\Credentials\Credential;
use App\Credentials\CredentialDecryptionException;
use App\Credentials\CredentialOwnerNotDeclaredException;
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

    expect($vault->getSystem('azdo.pat'))->toBe('my-pat-value');
});

it('returns null for missing credential', function () {
    $vault = new Vault(new Recorder, app(CredentialResolver::class));

    expect($vault->getSystem('missing.key'))->toBeNull();
});

it('fails loudly when credential payload cannot be decrypted', function () {
    DB::table('credentials')->insert([
        'integration_key' => 'azdo.pat',
        'owner_user_id' => null,
        'value' => 'invalid-payload',
    ]);

    expect(fn () => vault()->getSystem('azdo.pat'))
        ->toThrow(CredentialDecryptionException::class);
});

it('replaces an undecryptable credential through set', function () {
    DB::table('credentials')->insert([
        'integration_key' => 'azdo.pat',
        'owner_user_id' => null,
        'value' => 'invalid-payload',
    ]);

    vault()->set('azdo.pat', null, 'fresh-token');

    expect(vault()->getSystem('azdo.pat'))->toBe('fresh-token');
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

it('forgets only the credential in the given owner scope and audits the deletion', function () {
    $user = User::factory()->create();
    $vault = vault();
    $vault->set('azdo.pat', null, 'system-token');
    $vault->set('azdo.pat', $user->id, 'user-token');

    expect($vault->forget('azdo.pat', null))->toBeTrue();

    expect(Credential::query()->where('integration_key', 'azdo.pat')->whereNull('owner_user_id')->exists())->toBeFalse()
        ->and($vault->runAsOwner($user->id, fn (): ?string => $vault->get('azdo.pat')))->toBe('user-token');

    $log = AuditLog::where('action', 'credential_change')->latest('id')->first();
    expect($log?->subject_id)->toBe('azdo.pat')
        ->and($log?->payload_json)->toMatchArray(['actor' => 'system', 'outcome' => 'deleted']);
});

it('forgets an undecryptable credential', function () {
    DB::table('credentials')->insert([
        'integration_key' => 'azdo.pat',
        'owner_user_id' => null,
        'value' => 'invalid-payload',
    ]);

    expect(vault()->forget('azdo.pat', null))->toBeTrue()
        ->and(vault()->getSystem('azdo.pat'))->toBeNull();
});

it('does not audit forgetting a credential that is not stored', function () {
    expect(vault()->forget('missing.key', null))->toBeFalse()
        ->and(AuditLog::where('action', 'credential_change')->exists())->toBeFalse();
});

it('updates existing credential on re-set', function () {
    $vault = vault();

    $vault->set('azdo.pat', null, 'first-value');
    $vault->set('azdo.pat', null, 'second-value');

    expect(Credential::count())->toBe(1)
        ->and($vault->getSystem('azdo.pat'))->toBe('second-value');
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

    expect($vault->getSystem('azdo.pat'))->toBe('system-token')
        ->and($vault->runAsOwner($user->id, fn (): ?string => $vault->get('azdo.pat')))->toBe('user-token');
});

it('refuses to read a credential without a declared owner instead of defaulting to system', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $vault = vault();
    $vault->set('azdo.pat', null, 'system-token');

    expect(fn () => $vault->get('azdo.pat'))
        ->toThrow(CredentialOwnerNotDeclaredException::class, 'Credential [azdo.pat] was read without declaring its owner');
});

it('reads the system credential explicitly, whatever owner scope encloses the call', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $vault = vault();

    $vault->set('azdo.pat', null, 'system-token');
    $vault->set('azdo.pat', $user->id, 'user-token');

    expect($vault->getSystem('azdo.pat'))->toBe('system-token')
        ->and($vault->runAsOwner($user->id, fn (): ?string => $vault->getSystem('azdo.pat')))->toBe('system-token');
});

it('never falls back to the system credential inside a user owner scope', function () {
    $user = User::factory()->create();
    $vault = vault();
    $vault->set('azdo.pat', null, 'system-token');

    expect($vault->runAsOwner($user->id, fn (): ?string => $vault->get('azdo.pat')))->toBeNull();
});

it('resolves the scoped owner set via runAsOwner regardless of the authenticated user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $vault = vault();

    $vault->set('azdo.pat', null, 'system-token');
    $vault->set('azdo.pat', $user->id, 'user-token');

    $resolved = $vault->runAsOwner(null, fn (): ?string => $vault->get('azdo.pat'));

    expect($resolved)->toBe('system-token');
});

it('restores the enclosing owner scope after a nested runAsOwner', function () {
    $user = User::factory()->create();
    $vault = vault();
    $vault->set('azdo.pat', null, 'system-token');
    $vault->set('azdo.pat', $user->id, 'user-token');

    $resolved = $vault->runAsOwner($user->id, function () use ($vault): array {
        $nested = $vault->runAsOwner(null, fn (): ?string => $vault->get('azdo.pat'));

        return [$nested, $vault->get('azdo.pat')];
    });

    expect($resolved)->toBe(['system-token', 'user-token'])
        ->and(fn () => $vault->get('azdo.pat'))->toThrow(CredentialOwnerNotDeclaredException::class);
});

it('returns the override value instead of the stored credential during the callback', function () {
    $vault = vault();
    $vault->set('azdo.pat', null, 'system-token');

    $resolved = $vault->runWithOverrides(
        ['azdo.pat' => 'explicit-pat'],
        fn (): ?string => $vault->get('azdo.pat'),
    );

    expect($resolved)->toBe('explicit-pat')
        ->and($vault->getSystem('azdo.pat'))->toBe('system-token');
});

it('restores the previous override state after the callback throws', function () {
    $vault = vault();
    $vault->set('azdo.pat', null, 'system-token');

    expect(fn () => $vault->runWithOverrides(
        ['azdo.pat' => 'explicit-pat'],
        function (): never {
            throw new RuntimeException('boom');
        },
    ))->toThrow(RuntimeException::class, 'boom');

    expect($vault->getSystem('azdo.pat'))->toBe('system-token');
});

function vault(): Vault
{
    return new Vault(new Recorder, app(CredentialResolver::class));
}
