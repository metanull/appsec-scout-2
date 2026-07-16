<?php

namespace App\Trackers\GitHub;

use App\Credentials\CredentialField;
use App\Credentials\Vault;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Dto\CreateWorkItemRequest;
use App\Trackers\Dto\ReconciliationCandidateDto;
use App\Trackers\Dto\UpdateWorkItemRequest;
use App\Trackers\Dto\WorkItemDto;
use App\Trackers\ValueObjects\TestResult;
use App\Trackers\ValueObjects\TrackerCapabilities;

final class GitHubTracker implements Tracker
{
    private ?GitHubClient $client = null;

    private ?string $clientFingerprint = null;

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

    /** @return list<CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(key: 'github.token', label: 'Personal Access Token', isSecret: true, required: true, description: 'The GitHub personal access token (classic or fine-grained).'),
        ];
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

    public function fetchPriorities(string $projectKey): iterable
    {
        return [];
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

    /** @return iterable<ReconciliationCandidateDto> */
    public function reconciliationCandidates(string $projectKey): iterable
    {
        if (trim($projectKey) === '') {
            return [];
        }

        return $this->getClient()->searchForReconciliation($projectKey, 500);
    }

    private function getClient(): GitHubClient
    {
        if ($this->client instanceof GitHubClient && $this->clientFingerprint === null) {
            return $this->client;
        }

        $token = $this->vault->get('github.token', null) ?? throw new \RuntimeException('Missing GitHub credential: github.token');

        $fingerprint = hash('sha256', $token);

        if ($this->client instanceof GitHubClient && $this->clientFingerprint === $fingerprint) {
            return $this->client;
        }

        $this->clientFingerprint = $fingerprint;

        return $this->client = new GitHubClient($token);
    }
}
