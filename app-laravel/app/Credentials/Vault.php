<?php

namespace App\Credentials;

use App\Audit\Recorder;

class Vault
{
    public function __construct(private readonly Recorder $recorder) {}

    public function get(string $key, ?int $userId): ?string
    {
        $credential = $this->findCredential($key, $userId);

        return $credential?->value;
    }

    public function set(string $key, ?int $userId, string $value, ?string $description = null): void
    {
        Credential::updateOrCreate(
            ['integration_key' => $key, 'owner_user_id' => $userId],
            array_filter([
                'value' => $value,
                'description' => $description,
            ], fn (mixed $v) => $v !== null),
        );

        $actor = $userId !== null ? "user:{$userId}" : 'system';
        $this->recorder->recordCredentialChange($key, $actor, 'set');
    }

    public function test(string $key, ?int $userId, callable $probe): TestResult
    {
        $credential = $this->findCredential($key, $userId);

        if ($credential === null) {
            return TestResult::missing();
        }

        try {
            $probe($credential->value);
            $this->markTested($credential, true, null);

            return TestResult::ok();
        } catch (\Throwable $e) {
            $this->markTested($credential, false, $e->getMessage());

            return TestResult::fail($e->getMessage());
        }
    }

    private function findCredential(string $key, ?int $userId): ?Credential
    {
        return Credential::query()
            ->where('integration_key', $key)
            ->where('owner_user_id', $userId)
            ->first();
    }

    private function markTested(Credential $credential, bool $ok, ?string $error): void
    {
        $credential->update([
            'last_tested_at' => now(),
            'last_tested_ok' => $ok,
            'last_tested_error' => $error,
        ]);
    }
}
