<?php

namespace App\SourceControl\Collection;

/**
 * The outcome of a single `git ls-remote <url> HEAD` probe, which has three
 * distinct results AnalyzeRepositoryJob must tell apart: the remote was
 * unreachable (a failure, counted as one), the remote answered but exposed
 * no usable head (an empty repository — analyse anyway), or the remote
 * answered with the commit a `--depth 1` clone would check out.
 */
final readonly class RemoteHead
{
    private function __construct(
        public bool $unreachable,
        public ?string $sha,
    ) {}

    public static function unreachable(): self
    {
        return new self(true, null);
    }

    public static function none(): self
    {
        return new self(false, null);
    }

    public static function at(string $sha): self
    {
        return new self(false, $sha);
    }
}
