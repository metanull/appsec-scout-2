<?php

namespace App\SourceControl\Collection;

/**
 * The identifying data for exactly one Azure DevOps repository a
 * CollectRepositoryJob should clone and scan — the same fields a
 * `run.jsonl` line carries, without ever writing one.
 */
final readonly class RepositoryCollectionTarget
{
    public function __construct(
        public string $projectId,
        public string $projectName,
        public ?string $projectDescription,
        public ?string $projectUrl,
        public string $repositoryId,
        public string $repositoryName,
        public ?string $repositoryBrowseUrl,
        public string $repositoryCloneUrl,
        public ?string $defaultBranch,
    ) {}
}
