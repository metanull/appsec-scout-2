<?php

namespace App\Trackers\Dto;

final class UserDto
{
    public function __construct(
        public readonly string $id,
        public readonly string $displayName,
        public readonly ?string $email = null,
    ) {}
}
