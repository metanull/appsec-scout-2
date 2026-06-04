<?php

namespace App\Sources\AzDo;

final class AzDoRepository
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $projectId = null,
        public readonly ?string $projectName = null,
        public readonly ?string $defaultBranch = null,
        public readonly ?string $remoteUrl = null,
        public readonly ?string $apiUrl = null,
        public readonly ?string $webUrl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            projectId: isset($data['project']['id']) ? (string) $data['project']['id'] : null,
            projectName: isset($data['project']['name']) ? (string) $data['project']['name'] : null,
            defaultBranch: isset($data['defaultBranch']) ? (string) $data['defaultBranch'] : null,
            remoteUrl: isset($data['remoteUrl']) ? (string) $data['remoteUrl'] : null,
            apiUrl: isset($data['url']) ? (string) $data['url'] : null,
            webUrl: isset($data['webUrl']) ? (string) $data['webUrl'] : null,
        );
    }
}
