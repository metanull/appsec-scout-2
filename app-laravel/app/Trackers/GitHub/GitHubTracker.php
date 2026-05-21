<?php

namespace App\Trackers\GitHub;

use App\Credentials\Vault;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Dto\CreateWorkItemRequest;
use App\Trackers\Dto\UpdateWorkItemRequest;
use App\Trackers\Dto\WorkItemDto;
use App\Trackers\ValueObjects\TestResult;
use App\Trackers\ValueObjects\TrackerCapabilities;

final class GitHubTracker implements Tracker
{
    private ?GitHubClient $client = null;

    public function __construct(private readonly Vault $vault) {}

    public function id(): string
    {
        return 'github';
    }

    public function displayName(): string
    {
        return 'GitHub Issues';
    }

    public function capabilities(): TrackerCapabilities
    {
        return new TrackerCapabilities(
            supportsLabels: true,
            supportsPriority: false,
            supportsAssignee: true,
            supportsParent: false,
            supportedItemTypes: ['issue'],
            maxDescriptionBytes: 65536,
        );
    }

    /** @return list<string> */
    public function requiredCredentialKeys(): array
    {
        return ['github.token'];
    }

    public function testConnection(): TestResult
    {
        try {
            $this->getClient()->getCurrentUser();

            return TestResult::success();
        } catch (\Throwable $exception) {
            return TestResult::failure($exception->getMessage());
        }
    }

    public function fetchProjects(): iterable
    {
        return $this->getClient()->fetchProjects();
    }

    public function fetchItemTypes(string $projectKey): iterable
    {
        return $this->getClient()->fetchItemTypes($projectKey);
    }

    public function fetchAssigneeCandidates(string $projectKey, string $query): iterable
    {
        return $this->getClient()->fetchAssigneeCandidates($projectKey, $query);
    }

    public function createWorkItem(CreateWorkItemRequest $request): WorkItemDto
    {
        return $this->getClient()->createWorkItem($request);
    }

    public function getWorkItem(string $workItemKey): ?WorkItemDto
    {
        return $this->getClient()->getWorkItem($workItemKey);
    }

    public function updateWorkItem(string $workItemKey, UpdateWorkItemRequest $request): WorkItemDto
    {
        return $this->getClient()->updateWorkItem($workItemKey, $request);
    }

    public function searchWorkItems(string $projectKey, string $query, int $limit = 20): iterable
    {
        return $this->getClient()->searchWorkItems($projectKey, $query, $limit);
    }

    private function getClient(): GitHubClient
    {
        if ($this->client instanceof GitHubClient) {
            return $this->client;
        }

        $token = $this->vault->get('github.token', null) ?? throw new \RuntimeException('Missing GitHub credential: github.token');

        return $this->client = new GitHubClient($token);
    }
}
