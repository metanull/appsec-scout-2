<?php

namespace App\Trackers\Dto;

final class ProjectDto
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $url = null,
    ) {}
}
