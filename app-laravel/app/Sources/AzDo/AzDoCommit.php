<?php

namespace App\Sources\AzDo;

use Carbon\CarbonImmutable;

/** One entry of the Azure DevOps Git "Commits - Get Commits" response. */
final class AzDoCommit
{
    public function __construct(
        public readonly string $commitId,
        public readonly CarbonImmutable $committerDate,
        public readonly ?string $authorName = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $committer = is_array($data['committer'] ?? null) ? $data['committer'] : [];
        $author = is_array($data['author'] ?? null) ? $data['author'] : [];

        return new self(
            commitId: (string) $data['commitId'],
            committerDate: CarbonImmutable::parse((string) $committer['date'])->utc(),
            authorName: isset($author['name']) ? (string) $author['name'] : null,
        );
    }
}
