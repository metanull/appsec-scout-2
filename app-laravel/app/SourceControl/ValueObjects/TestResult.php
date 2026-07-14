<?php

namespace App\SourceControl\ValueObjects;

final class TestResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
    ) {}

    public static function success(): self
    {
        return new self(true);
    }

    public static function failure(string $error): self
    {
        return new self(false, $error);
    }
}
