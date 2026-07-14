<?php

namespace App\SourceControl\Contracts;

use App\Credentials\CredentialField;
use App\SourceControl\ValueObjects\TestResult;

interface SourceControlProvider
{
    public function id(): string;

    public function displayName(): string;

    /** @return list<CredentialField> */
    public function credentialFields(): array;

    public function testConnection(): TestResult;
}
