<?php

namespace App\Trackers\Jira;

use App\Http\OutboundHttpFactory;
use App\Trackers\Dto\CreateWorkItemRequest;
use App\Trackers\Dto\ProjectDto;
use App\Trackers\Dto\ReconciliationCandidateDto;
use App\Trackers\Dto\UpdateWorkItemRequest;
use App\Trackers\Dto\UserDto;
use App\Trackers\Dto\WorkItemDto;
use App\Trackers\Reconciliation\UrlExtractor;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

final class JiraClient
{
    private readonly Client $http;

    public function __construct(
        private readonly string $host,
        string $email,
        string $apiToken,
        ?Client $httpClient = null,
        /** @var list<string> */
        private readonly array $reconciliationLabels = ['security', 'vulnerability', 'appsec-scout'],
    ) {
        if ($httpClient !== null) {
            $this->http = $httpClient;

            return;
        }

        $this->http = OutboundHttpFactory::create([
            'base_uri' => rtrim($this->host, '/') . '/',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($email . ':' . $apiToken),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function getCurrentUser(): array
    {
        return $this->decode($this->http->get('rest/api/3/myself'));
    }

    /** @return list<ProjectDto> */
    public function fetchProjects(): array
    {
        $projects = [];
        $startAt = 0;

        do {
            $payload = $this->decode($this->http->get('rest/api/3/project/search', [
                'query' => [
                    'startAt' => $startAt,
                    'maxResults' => 50,
                ],
            ]));

            foreach (($payload['values'] ?? []) as $project) {
                $projects[] = new ProjectDto(
                    key: (string) $project['key'],
                    name: (string) $project['name'],
                    url: isset($project['self']) ? (string) $project['self'] : null,
                );
            }

            $startAt += (int) ($payload['maxResults'] ?? 50);
        } while (($payload['isLast'] ?? true) === false);

        return $projects;
    }

    /** @return list<string> */
    public function fetchItemTypes(string $projectKey): array
    {
        $payload = $this->decode($this->http->get('rest/api/3/issue/createmeta', [
            'query' => [
                'projectKeys' => $projectKey,
                'expand' => 'projects.issuetypes',
            ],
        ]));

        $types = $payload['projects'][0]['issuetypes'] ?? [];

        return array_values(array_map(
            static fn (array $type): string => (string) $type['name'],
            $types,
        ));
    }

    /** @return list<UserDto> */
    public function fetchAssigneeCandidates(string $projectKey, string $query): array
    {
        $payload = $this->decode($this->http->get('rest/api/3/user/assignable/search', [
            'query' => [
                'project' => $projectKey,
                'query' => $query,
            ],
        ]));

        return array_values(array_map(
            static fn (array $user): UserDto => new UserDto(
                id: (string) $user['accountId'],
                displayName: (string) $user['displayName'],
                email: isset($user['emailAddress']) ? (string) $user['emailAddress'] : null,
            ),
            $payload,
        ));
    }

    public function createWorkItem(CreateWorkItemRequest $request): WorkItemDto
    {
        $payload = $this->decode($this->http->post('rest/api/3/issue', [
            'json' => [
                'fields' => $this->buildFields($request),
            ],
        ]));

        return new WorkItemDto(
            id: (string) $payload['key'],
            projectKey: $request->projectKey,
            title: $request->title,
            state: 'Unknown',
            url: rtrim($this->host, '/') . '/browse/' . $payload['key'],
            itemType: $request->itemType,
            priority: $request->priority,
            assignee: $request->assigneeId !== null ? new UserDto($request->assigneeId, $request->assigneeId) : null,
            parentId: $request->parentId,
            labels: $request->labels,
            description: $request->description,
        );
    }

    public function getWorkItem(string $workItemKey): ?WorkItemDto
    {
        try {
            $payload = $this->decode($this->http->get("rest/api/3/issue/{$workItemKey}", [
                'query' => [
                    'fields' => 'summary,status,labels,priority,assignee,parent,description',
                ],
            ]));
        } catch (ClientException $exception) {
            if ($exception->getResponse()->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }

        return $this->mapWorkItem($payload);
    }

    public function updateWorkItem(string $workItemKey, UpdateWorkItemRequest $request): WorkItemDto
    {
        $fields = array_filter([
            'summary' => $request->title,
            'description' => $request->description !== null ? MarkdownToAdf::convert($request->description) : null,
            'labels' => $request->labels,
            'priority' => $request->priority !== null ? ['name' => $request->priority] : null,
            'assignee' => $request->assigneeId !== null ? ['accountId' => $request->assigneeId] : null,
            'parent' => $request->parentId !== null ? ['key' => $request->parentId] : null,
        ], static fn (mixed $value): bool => $value !== null);

        if ($fields !== []) {
            $this->http->put("rest/api/3/issue/{$workItemKey}", [
                'json' => ['fields' => $fields],
            ]);
        }

        if ($request->state !== null) {
            $this->transitionIssue($workItemKey, $request->state);
        }

        return $this->getWorkItem($workItemKey) ?? throw new \RuntimeException("Missing Jira issue {$workItemKey} after update");
    }

    /** @return list<WorkItemDto> */
    public function searchWorkItems(string $projectKey, string $query, int $limit = 20): array
    {
        $payload = $this->decode($this->http->get('rest/api/3/search', [
            'query' => [
                'jql' => sprintf(
                    'project = "%s" AND summary ~ "%s" ORDER BY created DESC',
                    $projectKey,
                    addcslashes($query, '"\\'),
                ),
                'maxResults' => $limit,
                'fields' => 'summary,status,labels,priority,assignee,parent,description',
            ],
        ]));

        return array_values(array_map(fn (array $issue): WorkItemDto => $this->mapWorkItem($issue), $payload['issues'] ?? []));
    }

    /** @return list<ReconciliationCandidateDto> */
    public function searchForReconciliation(string $projectKey, int $limit = 500): array
    {
        if (trim($projectKey) === '' || $limit <= 0) {
            return [];
        }

        $results = [];
        $nextPageToken = null;
        $jql = $this->reconciliationJql($projectKey);

        do {
            $query = [
                'jql' => $jql,
                'fields' => 'summary,status,description,labels',
                'maxResults' => min(100, max(1, $limit - count($results))),
            ];

            if ($nextPageToken !== null) {
                $query['nextPageToken'] = $nextPageToken;
            }

            $payload = $this->decode($this->http->get('rest/api/3/search/jql', ['query' => $query]));

            foreach ($payload['issues'] ?? [] as $issue) {
                if (! is_array($issue)) {
                    continue;
                }

                $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];
                $description = $fields['description'] ?? null;

                $extractedUrls = is_array($description)
                    ? UrlExtractor::extractFromAdf($description)
                    : (is_string($description) ? UrlExtractor::extractFromText($description) : []);

                $results[] = new ReconciliationCandidateDto(
                    trackerId: 'jira',
                    workItemId: (string) ($issue['key'] ?? ''),
                    workItemUrl: isset($issue['key']) ? rtrim($this->host, '/') . '/browse/' . $issue['key'] : null,
                    title: (string) ($fields['summary'] ?? ''),
                    state: (string) ($fields['status']['name'] ?? 'Unknown'),
                    labels: array_values(array_map('strval', $fields['labels'] ?? [])),
                    extractedUrls: $extractedUrls,
                    searchStrategy: $jql,
                );

                if (count($results) >= $limit) {
                    break;
                }
            }

            $nextPageTokenValue = $payload['nextPageToken'] ?? null;
            $nextPageToken = is_string($nextPageTokenValue) && $nextPageTokenValue !== '' ? $nextPageTokenValue : null;
            $isLast = (bool) ($payload['isLast'] ?? true);
        } while (! $isLast && $nextPageToken !== null && count($results) < $limit);

        return $results;
    }

    private function reconciliationJql(string $projectKey): string
    {
        $quotedLabels = array_map(
            static fn (string $label): string => '"' . addcslashes($label, '"\\') . '"',
            array_values(array_filter(array_map('strval', $this->reconciliationLabels), static fn (string $label): bool => $label !== '')),
        );

        $labelsClause = $quotedLabels === [] ? '"security"' : implode(', ', $quotedLabels);

        return sprintf(
            'project = "%s" AND labels in (%s) AND created >= -365d ORDER BY created DESC',
            addcslashes($projectKey, '"\\'),
            $labelsClause,
        );
    }

    /** @return array<string, mixed> */
    private function buildFields(CreateWorkItemRequest $request): array
    {
        return array_filter([
            'project' => ['key' => $request->projectKey],
            'summary' => $request->title,
            'description' => MarkdownToAdf::convert($request->description),
            'issuetype' => ['name' => $request->itemType],
            'labels' => $request->labels !== [] ? $request->labels : null,
            'priority' => $request->priority !== null ? ['name' => $request->priority] : null,
            'assignee' => $request->assigneeId !== null ? ['accountId' => $request->assigneeId] : null,
            'parent' => $request->parentId !== null ? ['key' => $request->parentId] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function transitionIssue(string $workItemKey, string $targetState): void
    {
        $payload = $this->decode($this->http->get("rest/api/3/issue/{$workItemKey}/transitions"));
        $normalizedTarget = $this->normalizeState($targetState);

        foreach ($payload['transitions'] ?? [] as $transition) {
            $name = (string) ($transition['name'] ?? '');

            if ($this->normalizeState($name) !== $normalizedTarget) {
                continue;
            }

            $this->http->post("rest/api/3/issue/{$workItemKey}/transitions", [
                'json' => ['transition' => ['id' => $transition['id']]],
            ]);

            return;
        }

        throw new \RuntimeException("Unable to transition {$workItemKey} to {$targetState}");
    }

    /** @param  array<string, mixed>  $payload */
    private function mapWorkItem(array $payload): WorkItemDto
    {
        $fields = $payload['fields'] ?? [];
        $assignee = isset($fields['assignee']['accountId'])
            ? new UserDto(
                id: (string) $fields['assignee']['accountId'],
                displayName: (string) ($fields['assignee']['displayName'] ?? $fields['assignee']['accountId']),
                email: isset($fields['assignee']['emailAddress']) ? (string) $fields['assignee']['emailAddress'] : null,
            )
            : null;

        return new WorkItemDto(
            id: (string) $payload['key'],
            projectKey: explode('-', (string) $payload['key'])[0],
            title: (string) ($fields['summary'] ?? ''),
            state: (string) ($fields['status']['name'] ?? 'Unknown'),
            url: rtrim($this->host, '/') . '/browse/' . $payload['key'],
            itemType: isset($fields['issuetype']['name']) ? (string) $fields['issuetype']['name'] : null,
            priority: isset($fields['priority']['name']) ? (string) $fields['priority']['name'] : null,
            assignee: $assignee,
            parentId: isset($fields['parent']['key']) ? (string) $fields['parent']['key'] : null,
            labels: array_values(array_map('strval', $fields['labels'] ?? [])),
            description: isset($fields['description']) && is_array($fields['description'])
                ? AdfToText::toText($fields['description'])
                : null,
        );
    }

    private function normalizeState(string $state): string
    {
        return strtolower(str_replace(['_', '-'], ' ', trim($state)));
    }

    /** @return array<string, mixed> */
    private function decode(mixed $response): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
