<?php

namespace App\Triage;

use InvalidArgumentException;

class BinaryResolver
{
    public function resolve(string $binary): string
    {
        return match ($binary) {
            'git' => '/usr/bin/git',
            'trivy' => '/usr/bin/trivy',
            'java' => '/usr/bin/java',
            default => throw new InvalidArgumentException(sprintf('Binary [%s] is not allowed.', $binary)),
        };
    }
}
