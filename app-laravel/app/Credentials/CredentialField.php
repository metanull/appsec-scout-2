<?php

namespace App\Credentials;

final class CredentialField
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $isSecret,
        public readonly bool $required = true,
        public readonly ?string $description = null,
    ) {}

    public function stateKey(): string
    {
        return str_replace(['.', '-'], '_', $this->key);
    }
}
