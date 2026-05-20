<?php

namespace App\Sources\Dto;

final class ContainerDto
{
    public function __construct(
        public readonly string $sourceContainerId,
        public readonly string $name,
        public readonly string $sourceSystemId,
        public readonly ?string $kind = null,
        public readonly ?string $url = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $metadata = null,
    ) {}
}
