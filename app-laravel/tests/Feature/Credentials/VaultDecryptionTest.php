<?php

use App\Credentials\CredentialDecryptionException;
use App\Credentials\Vault;
use Illuminate\Support\Facades\DB;

it('throws a decryption exception naming the key when a stored value cannot be decrypted', function () {
    app(Vault::class)->set('azdo.pat', null, 'valid-secret');
    DB::table('credentials')->where('integration_key', 'azdo.pat')->update(['value' => 'garbage-not-ciphertext']);

    expect(fn () => app(Vault::class)->get('azdo.pat', null))
        ->toThrow(CredentialDecryptionException::class, 'azdo.pat');
});

it('still returns null for a missing credential', function () {
    expect(app(Vault::class)->get('azdo.pat', null))->toBeNull();
});

it('still returns the plaintext for a decryptable credential', function () {
    app(Vault::class)->set('azdo.pat', null, 'valid-secret');

    expect(app(Vault::class)->get('azdo.pat', null))->toBe('valid-secret');
});

it('reports the decryption failure from a vault connection test instead of missing', function () {
    app(Vault::class)->set('azdo.pat', null, 'valid-secret');
    DB::table('credentials')->where('integration_key', 'azdo.pat')->update(['value' => 'garbage-not-ciphertext']);

    $result = app(Vault::class)->test('azdo.pat', null, fn () => null);

    expect($result->ok)->toBeFalse()
        ->and($result->missing)->toBeFalse()
        ->and($result->error)->toContain('cannot be decrypted');
});
