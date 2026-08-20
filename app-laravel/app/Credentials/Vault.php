<?php

namespace App\Credentials;

use App\Audit\Recorder;
use App\Sync\CredentialResolver;
use Illuminate\Contracts\Encryption\DecryptException;

class Vault
{
    private bool $hasScopedOwner = false;

    private ?int $scopedOwnerId = null;

    /** @var array<string, string> */
    private array $valueOverrides = [];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly CredentialResolver $resolver,
    ) {}

    /**
     * @throws CredentialDecryptionException when a stored value cannot be decrypted
     *                                       (typically after an APP_KEY change)
     */
    public function get(string $key, ?int $userId): ?string
    {
        if (array_key_exists($key, $this->valueOverrides)) {
            return $this->valueOverrides[$key];
        }

        $credential = $this->resolveCredential($key, $userId);

        return $this->credentialValue($credential);
    }

    public function set(string $key, ?int $userId, string $value, ?string $description = null): void
    {
        $attributes = ['integration_key' => $key, 'owner_user_id' => $userId];
        $values = array_filter([
            'value' => $value,
            'description' => $description,
        ], fn (mixed $v) => $v !== null);

        try {
            Credential::updateOrCreate($attributes, $values);
        } catch (DecryptException) {
            // Eloquent's dirty comparison decrypts the original value; an unreadable
            // stored ciphertext (APP_KEY changed) would make replacing it impossible.
            // Recreate the row so storing a new value is always the repair path.
            Credential::query()
                ->where('integration_key', $key)
                ->when($userId === null, fn ($query) => $query->whereNull('owner_user_id'), fn ($query) => $query->where('owner_user_id', $userId))
                ->delete();

            Credential::query()->create([...$attributes, ...$values]);
        }

        $actor = $userId !== null ? "user:{$userId}" : 'system';
        $this->recorder->recordCredentialChange($key, $actor, 'set');
    }

    public function test(string $key, ?int $userId, callable $probe): TestResult
    {
        $credential = $this->resolveCredential($key, $userId);

        if ($credential === null) {
            return TestResult::missing();
        }

        try {
            $value = $this->credentialValue($credential);

            if ($value === null) {
                return TestResult::missing();
            }

            $probe($value);
            $this->markTested($credential, true, null);

            return TestResult::ok();
        } catch (\Throwable $e) {
            $this->markTested($credential, false, $e->getMessage());

            return TestResult::fail($e->getMessage());
        }
    }

    /**
     * @param  list<string>  $keys
     */
    public function markTestedKeys(array $keys, ?int $userId, bool $ok, ?string $error): void
    {
        foreach ($keys as $key) {
            $credential = $this->resolver->exact($key, $userId);

            if ($credential instanceof Credential) {
                $this->markTested($credential, $ok, $error);
            }
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runAsOwner(?int $ownerId, callable $callback): mixed
    {
        $previousHasScopedOwner = $this->hasScopedOwner;
        $previousScopedOwnerId = $this->scopedOwnerId;

        $this->hasScopedOwner = true;
        $this->scopedOwnerId = $ownerId;

        try {
            return $callback();
        } finally {
            $this->hasScopedOwner = $previousHasScopedOwner;
            $this->scopedOwnerId = $previousScopedOwnerId;
        }
    }

    /**
     * Run a callback where `get()` returns the given values for the given keys instead of
     * resolving them from stored credentials, e.g. for an operator-supplied credential that
     * overrides the system vault for a single command invocation without persisting it.
     *
     * @template TReturn
     *
     * @param  array<string, string>  $overrides
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runWithOverrides(array $overrides, callable $callback): mixed
    {
        $previousOverrides = $this->valueOverrides;
        $this->valueOverrides = array_merge($this->valueOverrides, $overrides);

        try {
            return $callback();
        } finally {
            $this->valueOverrides = $previousOverrides;
        }
    }

    private function resolveCredential(string $key, ?int $userId): ?Credential
    {
        if ($userId !== null) {
            return $this->resolver->exact($key, $userId);
        }

        return $this->resolver->exact($key, $this->hasScopedOwner ? $this->scopedOwnerId : null);
    }

    private function markTested(Credential $credential, bool $ok, ?string $error): void
    {
        $credential->update([
            'last_tested_at' => now(),
            'last_tested_ok' => $ok,
            'last_tested_error' => $error,
        ]);
    }

    /**
     * @throws CredentialDecryptionException when a stored value cannot be decrypted
     */
    private function credentialValue(?Credential $credential): ?string
    {
        return $credential instanceof Credential ? $credential->decryptedValue() : null;
    }
}
