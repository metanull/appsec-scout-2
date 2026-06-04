<?php

namespace App\SourceCode;

final readonly class CodeLocation
{
    public function __construct(
        public ?string $filePath = null,
        public ?int $startLine = null,
        public ?int $endLine = null,
        public ?string $branch = null,
        public ?string $commitSha = null,
    ) {}
}
