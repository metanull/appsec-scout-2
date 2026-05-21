<?php

namespace App\Triage;

final class TrivyRunResult
{
    public function __construct(
        public readonly string $sarif,
        public readonly ?int $attachmentId,
    ) {}
}
