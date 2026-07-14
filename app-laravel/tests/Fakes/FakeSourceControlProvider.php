<?php

namespace Tests\Fakes;

use App\Credentials\CredentialField;
use App\SourceControl\Contracts\SourceControlProvider;
use App\SourceControl\ValueObjects\TestResult;

class FakeSourceControlProvider implements SourceControlProvider
{
    private bool $connectionOk = true;

    public function id(): string
    {
        return 'fake-repos';
    }

    public function displayName(): string
    {
        return 'Fake Source Control';
    }

    /** @return list<CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(key: 'fake-repos.token', label: 'Token', isSecret: true, required: true),
        ];
    }

    public function testConnection(): TestResult
    {
        return $this->connectionOk ? TestResult::success() : TestResult::failure('connection refused');
    }

    public function withConnectionFailure(): self
    {
        $this->connectionOk = false;

        return $this;
    }
}
