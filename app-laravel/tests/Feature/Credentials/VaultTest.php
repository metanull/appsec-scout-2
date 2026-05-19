<?php

use App\Audit\AuditLog;
use App\Audit\Recorder;
use App\Credentials\Credential;
use App\Credentials\Vault;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->recorder = new Recorder;
    $this->vault = new Vault($this->recorder);
});

it('stores value encrypted in the database', function () {
    $this->vault->set('azdo.pat', null, 'super-secret-token');

    $raw = DB::table('credentials')
        ->where('integration_key', 'azdo.pat')
        ->value('value');

    expect($raw)->not()->toBe('super-secret-token');
    expect(Crypt::decryptString($raw))->toBe('super-secret-token');
});

it('round-trips get after set', function () {
    $this->vault->set('azdo.pat', null, 'my-pat-value');

    expect($this->vault->get('azdo.pat', null))->toBe('my-pat-value');
});

it('returns null for missing credential', function () {
    expect($this->vault->get('missing.key', null))->toBeNull();
});

it('writes audit row on set with value redacted', function () {
    $this->vault->set('azdo.pat', null, 'secret');

    $log = AuditLog::where('action', 'credential_change')->first();
    expect($log)->not()->toBeNull();

    $payload = $log->payload_json;
    expect($payload)->toHaveKey('actor')
        ->and($payload)->not()->toHaveKey('value')
        ->and($payload)->not()->toContain('secret');
});

it('updates existing credential on re-set', function () {
    $this->vault->set('azdo.pat', null, 'first-value');
    $this->vault->set('azdo.pat', null, 'second-value');

    expect(Credential::count())->toBe(1)
        ->and($this->vault->get('azdo.pat', null))->toBe('second-value');
});

it('test probe returns ok on success', function () {
    $this->vault->set('azdo.pat', null, 'valid-token');
    $result = $this->vault->test('azdo.pat', null, fn (string $v) => true);

    expect($result->ok)->toBeTrue()
        ->and($result->missing)->toBeFalse();

    $credential = Credential::where('integration_key', 'azdo.pat')->first();
    expect($credential->last_tested_ok)->toBeTrue();
});

it('test probe returns fail on exception', function () {
    $this->vault->set('azdo.pat', null, 'bad-token');
    $result = $this->vault->test('azdo.pat', null, function () {
        throw new RuntimeException('Auth failed');
    });

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe('Auth failed');
});

it('test returns missing when credential does not exist', function () {
    $result = $this->vault->test('nonexistent.key', null, fn (string $v) => null);

    expect($result->missing)->toBeTrue();
});

it('system and user credentials are stored separately', function () {
    $user = User::factory()->create();
    $this->vault->set('azdo.pat', null, 'system-token');
    $this->vault->set('azdo.pat', $user->id, 'user-token');

    expect($this->vault->get('azdo.pat', null))->toBe('system-token')
        ->and($this->vault->get('azdo.pat', $user->id))->toBe('user-token');
});
