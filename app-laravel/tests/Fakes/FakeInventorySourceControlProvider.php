<?php

namespace Tests\Fakes;

use App\Credentials\CredentialField;
use App\SourceControl\Contracts\EnumeratesInventory;
use App\SourceControl\Contracts\SourceControlProvider;
use App\SourceControl\ValueObjects\TestResult;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\SystemDto;

class FakeInventorySourceControlProvider implements EnumeratesInventory, SourceControlProvider
{
    /** @var list<SystemDto> */
    private array $projects = [];

    /** @var array<string, list<ContainerDto>> */
    private array $repositories = [];

    public function id(): string
    {
        return 'fake-inventory-repos';
    }

    public function displayName(): string
    {
        return 'Fake Inventory Source Control';
    }

    /** @return list<CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(key: 'fake-inventory-repos.token', label: 'Token', isSecret: true, required: true),
        ];
    }

    public function testConnection(): TestResult
    {
        return TestResult::success();
    }

    /** @return iterable<SystemDto> */
    public function fetchProjects(): iterable
    {
        return $this->projects;
    }

    /** @return iterable<ContainerDto> */
    public function fetchRepositories(SystemDto $project): iterable
    {
        return $this->repositories[$project->sourceSystemId] ?? [];
    }

    public function withProjects(SystemDto ...$projects): self
    {
        $this->projects = $projects;

        return $this;
    }

    public function withRepositories(string $projectId, ContainerDto ...$repositories): self
    {
        $this->repositories[$projectId] = $repositories;

        return $this;
    }
}
