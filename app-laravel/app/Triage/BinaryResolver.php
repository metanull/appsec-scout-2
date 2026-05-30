<?php

namespace App\Triage;

use InvalidArgumentException;
use RuntimeException;

class BinaryResolver
{
    public function resolve(string $binary): string
    {
        $path = match ($binary) {
            'git' => '/usr/bin/git',
            'trivy' => '/usr/bin/trivy',
            'java' => '/usr/bin/java',
            default => throw new InvalidArgumentException(sprintf('Binary [%s] is not allowed.', $binary)),
        };

        if (! is_executable($path)) {
            throw new RuntimeException(sprintf('Binary [%s] is not available at [%s].', $binary, $path));
        }

        return $path;
    }
}
