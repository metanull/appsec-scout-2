<?php

namespace App\Trackers\GitHub;

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
use Illuminate\Support\Sleep;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class GitHubClient
{
    /** The search quota resets every minute, so a longer wait means something else is wrong. */
    private const int SEARCH_RATE_LIMIT_MAX_WAIT_SECONDS = 90;

    private const int SEARCH_RATE_LIMIT_MAX_ATTEMPTS = 3;

    /** GitHub's documented minimum wait after a secondary rate limit that names no reset. */
    private const int SECONDARY_RATE_LIMIT_WAIT_SECONDS = 60;

    private readonly Client $http;

    public function __construct(
        string $token,
        string $baseUrl = 'https://api.github.com',
        ?Client $httpClient = null,
    ) {
        if ($httpClient !== null) {
            $this->http = $httpClient;

            return;
        }

        $this->http = OutboundHttpFactory::create([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function getCurrentUser(): array
    {
        return $this->decode($this->http->get('user'));
    }

    /** @return list<ProjectDto> */
    public function fetchProjects(): array
    {
        $projects = [];
        $page = 1;

        do {
            $payload = $this->decodeList($this->http->get('user/repos', [
                'query' => [
                    'affiliation' => 'owner,collaborator,organization_member',
                    'per_page' => 100,
                    'page' => $page,
                ],
            ]));

            foreach ($payload as $repo) {
                $projects[] = new ProjectDto(
                    key: (string) $repo['full_name'],
                    name: (string) $repo['full_name'],
                    url: isset($repo['html_url']) ? (string) $repo['html_url'] : null,
                    owner: isset($repo['owner']['login']) ? (string) $repo['owner']['login'] : null,
                    repository: isset($repo['name']) ? (string) $repo['name'] : null,
                );
            }

            $page++;
        } while ($payload !== []);

        return $projects;
    }

    /** @return list<string> */
    public function fetchItemTypes(string $projectKey): array
    {
        return ['issue'];
    }

    /** @return list<UserDto> */
    public function fetchAssigneeCandidates(string $projectKey, string $query): array
    {
        [$owner, $repo] = $this->splitProjectKey($projectKey);
        $payload = $this->decodeList($this->http->get("repos/{$owner}/{$repo}/assignees", [
            'query' => ['per_page' => 100],
        ]));

        $normalizedQuery = strtolower($query);

        return array_values(array_filter(array_map(
            static fn (array $user): UserDto => new UserDto(
                id: (string) $user['login'],
                displayName: (string) $user['login'],
                email: null,
            ),
            $payload,
        ), static fn (UserDto $user): bool => $normalizedQuery === '' || str_contains(strtolower($user->displayName), $normalizedQuery)));
    }

    public function createWorkItem(CreateWorkItemRequest $request): WorkItemDto
    {
        [$owner, $repo] = $this->splitProjectKey($request->projectKey);
        $body = $request->parentId !== null
            ? sprintf("Parent: %s\n\n%s", $request->parentId, $request->description)
            : $request->description;

        $payload = $this->decode($this->http->post("repos/{$owner}/{$repo}/issues", [
            'json' => array_filter([
                'title' => $request->title,
                'body' => $body,
                'labels' => $request->labels !== [] ? $request->labels : null,
                'assignees' => $request->assigneeId !== null ? [$request->assigneeId] : null,
            ], static fn (mixed $value): bool => $value !== null),
        ]));

        return $this->mapWorkItem($payload, $request->projectKey, $request->parentId);
    }

    public function getWorkItem(string $workItemKey): ?WorkItemDto
    {
        [$owner, $repo, $number] = $this->splitWorkItemKey($workItemKey);

        try {
            $payload = $this->decode($this->http->get("repos/{$owner}/{$repo}/issues/{$number}"));
        } catch (ClientException $exception) {
            if ($exception->getResponse()->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }

        return $this->mapWorkItem($payload, "{$owner}/{$repo}", $this->extractParentFromBody($payload['body'] ?? null));
    }

    public function updateWorkItem(string $workItemKey, UpdateWorkItemRequest $request): WorkItemDto
    {
        [$owner, $repo, $number] = $this->splitWorkItemKey($workItemKey);

        $payload = $this->decode($this->http->patch("repos/{$owner}/{$repo}/issues/{$number}", [
            'json' => array_filter([
                'title' => $request->title,
                'body' => $request->description !== null
                    ? $this->prefixParent($request->description, $request->parentId)
                    : null,
                'labels' => $request->labels,
                'assignees' => $request->assigneeId !== null ? [$request->assigneeId] : null,
                'state' => $request->state !== null ? $this->mapStateValue($request->state) : null,
                'state_reason' => $request->state !== null ? $this->mapStateReason($request->state) : null,
            ], static fn (mixed $value): bool => $value !== null),
        ]));

        return $this->mapWorkItem($payload, "{$owner}/{$repo}", $request->parentId ?? $this->extractParentFromBody($payload['body'] ?? null));
    }

    public function addComment(string $workItemKey, string $markdown): void
    {
        [$owner, $repo, $number] = $this->splitWorkItemKey($workItemKey);

        $this->http->post("repos/{$owner}/{$repo}/issues/{$number}/comments", [
            'json' => ['body' => $markdown],
        ]);
    }

    /** @return list<WorkItemDto> */
    public function searchWorkItems(string $projectKey, string $query, int $limit = 20): array
    {
        [$owner, $repo] = $this->splitProjectKey($projectKey);
        $payload = $this->decode($this->http->get('search/issues', [
            'query' => [
                'q' => sprintf('repo:%s/%s %s is:issue', $owner, $repo, $query),
                'per_page' => $limit,
            ],
        ]));

        return array_values(array_map(
            fn (array $issue): WorkItemDto => $this->mapWorkItem($issue, $projectKey, $this->extractParentFromBody($issue['body'] ?? null)),
            $payload['items'] ?? [],
        ));
    }

    /** @return list<ReconciliationCandidateDto> */
    public function searchForReconciliation(string $projectKey, int $limit = 500): array
    {
        if (trim($projectKey) === '' || $limit <= 0) {
            return [];
        }

        [$owner, $repo] = $this->splitProjectKey($projectKey);
        $since = now()->subDays(365)->toDateString();
        $queryString = sprintf('type:issue label:security repo:%s/%s created:>=%s', $owner, $repo, $since);

        $results = [];
        $page = 1;
        $perPage = 100;

        do {
            $payload = $this->rateLimitedSearch([
                'q' => $queryString,
                'per_page' => min($perPage, max(1, $limit - count($results))),
                'page' => $page,
            ]);

            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $number = (int) ($item['number'] ?? 0);
                if ($number <= 0) {
                    continue;
                }

                $results[] = new ReconciliationCandidateDto(
                    trackerId: 'github',
                    workItemId: sprintf('%s/%s#%d', $owner, $repo, $number),
                    workItemUrl: isset($item['html_url']) ? (string) $item['html_url'] : null,
                    title: (string) ($item['title'] ?? ''),
                    state: $this->displayState((string) ($item['state'] ?? 'open'), isset($item['state_reason']) ? (string) $item['state_reason'] : null),
                    labels: array_values(array_map(static fn (array|string $label): string => is_array($label) ? (string) ($label['name'] ?? '') : (string) $label, $item['labels'] ?? [])),
                    extractedUrls: UrlExtractor::extractFromMarkdown((string) ($item['body'] ?? '')),
                    searchStrategy: $queryString,
                );

                if (count($results) >= $limit) {
                    break;
                }
            }

            $totalCount = (int) ($payload['total_count'] ?? 0);
            $page++;
        } while ($items !== [] && count($results) < min($limit, $totalCount === 0 ? $limit : $totalCount));

        return $results;
    }

    /**
     * Runs one Search API request for reconciliation, waiting out GitHub's search rate limit
     * (about 30 requests a minute) instead of failing: it pauses once a response reports the
     * quota spent, and retries a request refused with a rate-limit 403/429 after the wait
     * GitHub asks for. A wait longer than the cap, or a refusal past the retry budget, fails.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function rateLimitedSearch(array $query): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http->get('search/issues', ['query' => $query]);
            } catch (ClientException $exception) {
                $wait = $this->rateLimitWaitSeconds($exception->getResponse());

                if ($wait === null || $attempt >= self::SEARCH_RATE_LIMIT_MAX_ATTEMPTS) {
                    throw $exception;
                }

                if ($wait > self::SEARCH_RATE_LIMIT_MAX_WAIT_SECONDS) {
                    throw new RuntimeException(sprintf(
                        'GitHub search rate limit exceeded; it resets in %d s, longer than the %d s reconciliation waits.',
                        $wait,
                        self::SEARCH_RATE_LIMIT_MAX_WAIT_SECONDS,
                    ), previous: $exception);
                }

                Sleep::for($wait)->seconds();

                continue;
            }

            $this->pauseWhenSearchQuotaSpent($response);

            return $this->decode($response);
        }
    }

    /** Seconds GitHub asks to wait before retrying, or null when the refusal is not a rate limit. */
    private function rateLimitWaitSeconds(ResponseInterface $response): ?int
    {
        if (! in_array($response->getStatusCode(), [403, 429], true)) {
            return null;
        }

        $retryAfter = $response->getHeaderLine('Retry-After');

        if ($retryAfter !== '' && ctype_digit($retryAfter)) {
            return max(1, (int) $retryAfter);
        }

        if ($response->getHeaderLine('X-RateLimit-Remaining') === '0') {
            return $this->secondsUntilRateLimitReset($response) ?? self::SECONDARY_RATE_LIMIT_WAIT_SECONDS;
        }

        // A secondary rate limit may come without either header; GitHub then asks for at
        // least a minute's wait. Any other 403 (e.g. missing permission) is not retried.
        $payload = json_decode((string) $response->getBody(), true);
        $message = is_array($payload) && is_string($payload['message'] ?? null) ? $payload['message'] : '';

        return str_contains(strtolower($message), 'rate limit') ? self::SECONDARY_RATE_LIMIT_WAIT_SECONDS : null;
    }

    private function pauseWhenSearchQuotaSpent(ResponseInterface $response): void
    {
        if ($response->getHeaderLine('X-RateLimit-Remaining') !== '0') {
            return;
        }

        $wait = $this->secondsUntilRateLimitReset($response);

        if ($wait !== null) {
            Sleep::for(min($wait, self::SEARCH_RATE_LIMIT_MAX_WAIT_SECONDS))->seconds();
        }
    }

    private function secondsUntilRateLimitReset(ResponseInterface $response): ?int
    {
        $reset = $response->getHeaderLine('X-RateLimit-Reset');

        if ($reset === '' || ! ctype_digit($reset)) {
            return null;
        }

        // The quota refills at the reset second itself, so wait one second past it.
        return max(1, (int) $reset - now()->getTimestamp() + 1);
    }

    /** @param  array<string, mixed>  $payload */
    private function mapWorkItem(array $payload, string $projectKey, ?string $parentId): WorkItemDto
    {
        $assignee = isset($payload['assignees'][0]['login'])
            ? new UserDto(
                id: (string) $payload['assignees'][0]['login'],
                displayName: (string) $payload['assignees'][0]['login'],
            )
            : null;

        return new WorkItemDto(
            id: sprintf('%s#%d', $projectKey, $payload['number']),
            projectKey: $projectKey,
            title: (string) $payload['title'],
            state: $this->displayState((string) $payload['state'], isset($payload['state_reason']) ? (string) $payload['state_reason'] : null),
            url: isset($payload['html_url']) ? (string) $payload['html_url'] : null,
            itemType: 'issue',
            assignee: $assignee,
            parentId: $parentId,
            labels: array_values(array_map(static fn (array|string $label): string => is_array($label) ? (string) $label['name'] : (string) $label, $payload['labels'] ?? [])),
            description: isset($payload['body']) ? (string) $payload['body'] : null,
        );
    }

    /** @return array{0: string, 1: string} */
    private function splitProjectKey(string $projectKey): array
    {
        $parts = explode('/', $projectKey, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException("Invalid GitHub project key: {$projectKey}");
        }

        return [$parts[0], $parts[1]];
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function splitWorkItemKey(string $workItemKey): array
    {
        if (! preg_match('/^([^\/]+)\/([^#]+)#(\d+)$/', $workItemKey, $matches)) {
            throw new \InvalidArgumentException("Invalid GitHub work item key: {$workItemKey}");
        }

        return [$matches[1], $matches[2], (int) $matches[3]];
    }

    private function prefixParent(string $body, ?string $parentId): string
    {
        if ($parentId === null || $parentId === '') {
            return $body;
        }

        return sprintf("Parent: %s\n\n%s", $parentId, $body);
    }

    private function extractParentFromBody(mixed $body): ?string
    {
        if (! is_string($body)) {
            return null;
        }

        if (! preg_match('/^Parent:\s+([^\r\n]+)\r?\n\r?\n/', $body, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function mapStateValue(string $state): string
    {
        return match (strtolower($state)) {
            'resolved', 'dismissed' => 'closed',
            default => 'open',
        };
    }

    private function mapStateReason(string $state): ?string
    {
        return match (strtolower($state)) {
            'resolved' => 'completed',
            'dismissed' => 'not_planned',
            default => null,
        };
    }

    private function displayState(string $state, ?string $stateReason): string
    {
        if ($state !== 'closed') {
            return 'Open';
        }

        return $stateReason === 'not_planned' ? 'Closed (not planned)' : 'Closed';
    }

    /** @return array<string, mixed> */
    private function decode(mixed $response): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    private function decodeList(mixed $response): array
    {
        /** @var list<array<string, mixed>> $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
