<?php

namespace App\Sources\Dto;

use Carbon\CarbonImmutable;

/** The newest commit on a repository's default branch, as its Source Control provider reports it. */
final class CommitDto
{
    public function __construct(
        public readonly string $sha,
        public readonly CarbonImmutable $committedAt,
        public readonly ?string $author = null,
    ) {}
}
