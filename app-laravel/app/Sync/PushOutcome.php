<?php

namespace App\Sync;

/** What a push of one source's pending alert changes did. */
final readonly class PushOutcome
{
    public function __construct(
        public string $sourceId,
        public int $succeeded,
        public int $failed,
        public int $skipped,
        public int $resolvedLocalOnly,
        public ?string $lastError,
    ) {}
}
