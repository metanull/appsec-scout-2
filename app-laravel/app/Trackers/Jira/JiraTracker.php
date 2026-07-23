<?php

namespace App\Trackers\Jira;

use App\Credentials\CredentialField;
use App\Credentials\Vault;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Dto\CreateWorkItemRequest;
use App\Trackers\Dto\ReconciliationCandidateDto;
use App\Trackers\Dto\UpdateWorkItemRequest;
use App\Trackers\Dto\WorkItemDto;
use App\Trackers\ValueObjects\TestResult;
use App\Trackers\ValueObjects\TrackerCapabilities;

final class JiraTracker implements Tracker
{
    private ?JiraClient $client = null;

    private ?string $clientFingerprint = null;

    public function __construct(private readonly Vault $vault) {}

    public function id(): string
    {
        return 'jira';
    }

    public function displayName(): string
    {
        return 'Jira Cloud';
    }

    public function capabilities(): TrackerCapabilities
    {
        return new TrackerCapabilities(
            supportsLabels: true,
            supportsPriority: true,
            supportsAssignee: true,
            supportsParent: true,
            supportedItemTypes: ['Bug', 'Task', 'Story', 'Epic'],
            maxDescriptionBytes: 16384,
        );
    }

    /** @return list<CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(key: 'jira.host', label: 'Host URL', isSecret: false, required: true, description: 'The Jira Cloud instance URL (e.g. https://yourorg.atlassian.net).'),
            new CredentialField(key: 'jira.email', label: 'Email', isSecret: false, required: true, description: 'The Jira account email address.'),
            new CredentialField(key: 'jira.api_token', label: 'API Token', isSecret: true, required: true, description: 'The Jira API token generated from your Atlassian account.'),
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
        return $this->getClient()->fetchPriorities();
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

    private function getClient(): JiraClient
    {
        if ($this->client instanceof JiraClient && $this->clientFingerprint === null) {
            return $this->client;
        }

        $host = $this->vault->get('jira.host', null) ?? throw new \RuntimeException('Missing Jira credential: jira.host');
        $email = $this->vault->get('jira.email', null) ?? throw new \RuntimeException('Missing Jira credential: jira.email');
        $apiToken = $this->vault->get('jira.api_token', null) ?? throw new \RuntimeException('Missing Jira credential: jira.api_token');

        $fingerprint = hash('sha256', implode('|', [$host, $email, $apiToken]));

        if ($this->client instanceof JiraClient && $this->clientFingerprint === $fingerprint) {
            return $this->client;
        }

        $this->clientFingerprint = $fingerprint;

        $labels = config('reconciliation.jira.reconciliation_labels', ['security', 'vulnerability', 'appsec-scout']);
        $reconciliationLabels = is_array($labels) ? array_values(array_filter(array_map('strval', $labels), static fn (string $label): bool => $label !== '')) : ['security', 'vulnerability', 'appsec-scout'];

        return $this->client = new JiraClient($host, $email, $apiToken, null, $reconciliationLabels);
    }
}
