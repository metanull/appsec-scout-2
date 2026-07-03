<?php

namespace App\Assets\Parsers;

final class ParsedFinding
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $severity,
        public readonly string $filePath,
        public readonly ?int $startLine,
        public readonly ?int $endLine,
        public readonly ?string $packageName,
        public readonly ?string $packageVersion,
        public readonly array $metadata,
    ) {}
}
