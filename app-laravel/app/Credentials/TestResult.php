<?php

namespace App\Credentials;

class TestResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $missing,
        public readonly ?string $error,
    ) {}

    public static function ok(): self
    {
        return new self(ok: true, missing: false, error: null);
    }

    public static function fail(string $error): self
    {
        return new self(ok: false, missing: false, error: $error);
    }

    public static function missing(): self
    {
        return new self(ok: false, missing: true, error: null);
    }
}
