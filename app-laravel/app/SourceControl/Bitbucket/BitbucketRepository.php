<?php

namespace App\SourceControl\Bitbucket;

final class BitbucketRepository
{
    public function __construct(
        public readonly string $slug,
        public readonly string $fullName,
        public readonly string $name,
        public readonly ?string $mainBranch = null,
        public readonly ?string $htmlUrl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $slug = isset($data['slug']) && $data['slug'] !== ''
            ? (string) $data['slug']
            : (isset($data['name']) ? (string) $data['name'] : '');

        return new self(
            slug: $slug,
            fullName: isset($data['full_name']) ? (string) $data['full_name'] : $slug,
            name: isset($data['name']) ? (string) $data['name'] : $slug,
            mainBranch: isset($data['mainbranch']['name']) ? (string) $data['mainbranch']['name'] : null,
            htmlUrl: isset($data['links']['html']['href']) ? (string) $data['links']['html']['href'] : null,
        );
    }
}
