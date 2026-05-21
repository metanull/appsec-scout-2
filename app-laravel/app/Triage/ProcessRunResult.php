<?php

namespace App\Triage;

final class ProcessRunResult
{
    /** @param list<string> $command */
    public function __construct(
        public readonly array $command,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $exitCode,
    ) {}
}
