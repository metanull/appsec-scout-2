<?php

namespace App\Sources\Dto;

final class SystemDto
{
    public function __construct(
        public readonly string $sourceSystemId,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $url = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $metadata = null,
    ) {}
}
