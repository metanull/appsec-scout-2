<?php

use App\Credentials\Vault;
use App\Trackers\Dto\CreateWorkItemRequest;
use App\Trackers\Dto\UpdateWorkItemRequest;
use App\Trackers\GitHub\GitHubClient;
use App\Trackers\GitHub\GitHubTracker;
use App\Trackers\TrackerRateLimitExceededException;
use Carbon\CarbonInterval;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Sleep;

if (! function_exists('githubFixture')) {
    function githubFixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/Trackers/GitHub/{$name}"));
    }
}

function injectGitHubClient(GitHubTracker $tracker, GitHubClient $client): void
{
    $reflection = new ReflectionClass($tracker);
    $property = $reflection->getProperty('client');
    $property->setAccessible(true);
    $property->setValue($tracker, $client);
}

it('creates a github issue with labels assignee and parent prefix', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(201, [], githubFixture('create-issue-response.json')),
    ]));
    $stack->push(Middleware::history($history));

    $tracker = new GitHubTracker(app(Vault::class));
    injectGitHubClient($tracker, new GitHubClient(
        'token',
        'https://api.github.com',
        new Client(['handler' => $stack]),
    ));

    $workItem = $tracker->createWorkItem(new CreateWorkItemRequest(
        projectKey: 'octo-org/appsec-scout',
        itemType: 'issue',
        title: 'Grouped secret findings',
        description: 'Tracked from AppSec Scout.',
        labels: ['security', 'appsec-scout', 'secret'],
        assigneeId: 'octocat',
        parentId: 'octo-org/platform#7',
    ));

    expect($workItem->id)->toBe('octo-org/appsec-scout#101')
        ->and($history)->toHaveCount(1);

    $payload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['body'])->toStartWith("Parent: octo-org/platform#7\n\n")
        ->and($payload['labels'])->toBe(['security', 'appsec-scout', 'secret'])
        ->and($payload['assignees'])->toBe(['octocat']);
});

it('gets an existing github issue', function () {
    $tracker = new GitHubTracker(app(Vault::class));
    injectGitHubClient($tracker, new GitHubClient(
        'token',
        'https://api.github.com',
        new Client(['handler' => new MockHandler([
            new Response(200, [], githubFixture('get-issue-response.json')),
        ])]),
    ));

    $workItem = $tracker->getWorkItem('octo-org/appsec-scout#101');

    expect($workItem)->not->toBeNull()
        ->and($workItem?->title)->toBe('Grouped secret findings')
        ->and($workItem?->state)->toBe('Open')
        ->and($workItem?->parentId)->toBe('octo-org/platform#7');
});

it('updates a github issue and maps not planned closure state', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], githubFixture('updated-issue-response.json')),
    ]));
    $stack->push(Middleware::history($history));

    $tracker = new GitHubTracker(app(Vault::class));
    injectGitHubClient($tracker, new GitHubClient(
        'token',
        'https://api.github.com',
        new Client(['handler' => $stack]),
    ));

    $workItem = $tracker->updateWorkItem('octo-org/appsec-scout#101', new UpdateWorkItemRequest(
        title: 'Grouped secret findings (dismissed)',
        description: 'The finding does not require further action.',
        state: 'dismissed',
        labels: ['security', 'dismissed'],
        assigneeId: 'octocat',
    ));

    expect($workItem->state)->toBe('Closed (not planned)')
        ->and($history)->toHaveCount(1);

    $payload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['state'])->toBe('closed')
        ->and($payload['state_reason'])->toBe('not_planned');
});

it('tests github connectivity through the tracker contract', function () {
    app(Vault::class)->set('github.token', null, 'token');

    $tracker = new GitHubTracker(app(Vault::class));
    injectGitHubClient($tracker, new GitHubClient(
        'token',
        'https://api.github.com',
        new Client(['handler' => new MockHandler([
            new Response(200, [], githubFixture('user-response.json')),
        ])]),
    ));

    expect($tracker->testConnection()->ok)->toBeTrue();
});

it('maps github repositories to project dto with owner and repository facts', function () {
    $tracker = new GitHubTracker(app(Vault::class));
    injectGitHubClient($tracker, new GitHubClient(
        'token',
        'https://api.github.com',
        new Client(['handler' => new MockHandler([
            new Response(200, [], json_encode([
                [
                    'full_name' => 'octo-org/appsec-scout',
                    'name' => 'appsec-scout',
                    'html_url' => 'https://github.com/octo-org/appsec-scout',
                    'owner' => ['login' => 'octo-org'],
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([], JSON_THROW_ON_ERROR)),
        ])]),
    ));

    $projects = iterator_to_array($tracker->fetchProjects(), false);

    expect($projects)->toHaveCount(1)
        ->and($projects[0]->key)->toBe('octo-org/appsec-scout')
        ->and($projects[0]->name)->toBe('octo-org/appsec-scout')
        ->and($projects[0]->owner)->toBe('octo-org')
        ->and($projects[0]->repository)->toBe('appsec-scout')
        ->and($projects[0]->url)->toBe('https://github.com/octo-org/appsec-scout');
});

it('lists labelled issues across every repository the token can see, page by page', function () {
    $history = [];
    $firstPage = array_map(fn (int $number): array => githubIssue($number, $number % 2 === 0 ? 'octo-org/appsec-scout' : 'octo-user/tools'), range(1, 100));

    $tracker = githubReconciliationTracker([
        githubIssuesPage($firstPage),
        githubIssuesPage([githubIssue(101, 'octo-org/appsec-scout', body: 'Repository root https://dev.azure.com/acme/proj/_git/repo')]),
    ], $history);

    $candidates = iterator_to_array($tracker->reconciliationCandidates(), false);

    expect($candidates)->toHaveCount(101)
        ->and($candidates[0]->workItemId)->toBe('octo-user/tools#1')
        ->and($candidates[0]->extractedUrls)->toContain('https://example.com/finding/1')
        ->and($candidates[0]->project?->key)->toBe('octo-user/tools')
        ->and($candidates[0]->project?->url)->toBe('https://github.com/octo-user/tools')
        ->and($candidates[0]->project?->owner)->toBe('octo-user')
        ->and($candidates[0]->project?->repository)->toBe('tools')
        ->and($candidates[100]->workItemId)->toBe('octo-org/appsec-scout#101')
        ->and($history)->toHaveCount(2);

    $request = $history[0]['request'];
    parse_str($request->getUri()->getQuery(), $firstPageQuery);
    parse_str($history[1]['request']->getUri()->getQuery(), $secondPageQuery);

    expect($request->getUri()->getPath())->toBe('issues')
        ->and($firstPageQuery)->toMatchArray(['filter' => 'all', 'state' => 'all', 'labels' => 'security', 'sort' => 'created', 'direction' => 'desc', 'per_page' => '100', 'page' => '1'])
        ->and($secondPageQuery['page'])->toBe('2');
});

it('skips pull requests and stops at the first issue older than a year', function () {
    $history = [];

    $tracker = githubReconciliationTracker([
        githubIssuesPage([
            githubIssue(3),
            githubIssue(2, pullRequest: true),
            githubIssue(1, createdAt: now()->subDays(400)->toIso8601String()),
        ]),
    ], $history);

    $candidates = iterator_to_array($tracker->reconciliationCandidates(), false);

    expect(array_map(fn ($candidate): string => $candidate->workItemId, $candidates))->toBe(['octo-org/appsec-scout#3'])
        ->and($history)->toHaveCount(1);
});

it('lists each reconciliation label on its own and merges the issues', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        githubIssuesPage([githubIssue(1), githubIssue(2)]),
        githubIssuesPage([githubIssue(2), githubIssue(3)]),
    ]));
    $stack->push(Middleware::history($history));

    $tracker = new GitHubTracker(app(Vault::class));
    injectGitHubClient($tracker, new GitHubClient('token', 'https://api.github.com', new Client(['handler' => $stack]), ['security', 'vulnerability']));

    $candidates = iterator_to_array($tracker->reconciliationCandidates(), false);

    parse_str($history[1]['request']->getUri()->getQuery(), $secondQuery);

    expect(array_map(fn ($candidate): string => $candidate->workItemId, $candidates))
        ->toBe(['octo-org/appsec-scout#1', 'octo-org/appsec-scout#2', 'octo-org/appsec-scout#3'])
        ->and($secondQuery['labels'])->toBe('vulnerability');
});

/**
 * @param  list<Response>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function githubReconciliationTracker(array $responses, array &$history): GitHubTracker
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $tracker = new GitHubTracker(app(Vault::class));
    injectGitHubClient($tracker, new GitHubClient('token', 'https://api.github.com', new Client(['handler' => $stack])));

    return $tracker;
}

/** @return array<string, mixed> */
function githubIssue(int $number, string $repository = 'octo-org/appsec-scout', ?string $createdAt = null, bool $pullRequest = false, ?string $body = null): array
{
    [$owner, $name] = explode('/', $repository, 2);

    return array_filter([
        'number' => $number,
        'title' => "Security issue {$number}",
        'state' => 'open',
        'html_url' => "https://github.com/{$repository}/issues/{$number}",
        'labels' => [['name' => 'security']],
        'body' => $body ?? "See [Alert](https://example.com/finding/{$number})",
        'created_at' => $createdAt ?? now()->subDay()->toIso8601String(),
        'repository' => ['full_name' => $repository, 'name' => $name, 'html_url' => "https://github.com/{$repository}", 'owner' => ['login' => $owner]],
        'pull_request' => $pullRequest ? ['url' => "https://api.github.com/repos/{$repository}/pulls/{$number}"] : null,
    ], fn (mixed $value): bool => $value !== null);
}

/**
 * @param  list<array<string, mixed>>  $issues
 * @param  array<string, string>  $headers
 */
function githubIssuesPage(array $issues, array $headers = []): Response
{
    return new Response(200, $headers, json_encode($issues, JSON_THROW_ON_ERROR));
}

/**
 * A full page, so the listing asks for the next one.
 *
 * @param  array<string, string>  $headers
 */
function githubFullIssuesPage(int $firstNumber, array $headers = []): Response
{
    return githubIssuesPage(array_map(fn (int $number): array => githubIssue($number), range($firstNumber, $firstNumber + 99)), $headers);
}

function githubRateLimitRefusal(int $resetInSeconds): Response
{
    return new Response(403, [
        'X-RateLimit-Limit' => '5000',
        'X-RateLimit-Remaining' => '0',
        'X-RateLimit-Reset' => (string) (now()->getTimestamp() + $resetInSeconds),
    ], '{"message":"API rate limit exceeded for user ID 1."}');
}

function sleptSeconds(int $seconds): Closure
{
    return fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === $seconds;
}

it('waits out a github rate limit refusal and retries the listing', function () {
    $this->freezeTime();
    Sleep::fake();
    $history = [];

    $tracker = githubReconciliationTracker([githubRateLimitRefusal(30), githubIssuesPage([githubIssue(101)])], $history);

    $candidates = iterator_to_array($tracker->reconciliationCandidates(), false);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->workItemId)->toBe('octo-org/appsec-scout#101')
        ->and($history)->toHaveCount(2);

    Sleep::assertSlept(sleptSeconds(31), 1);
});

it('pauses before the next page once a quota that resets soon is spent', function () {
    $this->freezeTime();
    Sleep::fake();
    $history = [];

    $tracker = githubReconciliationTracker([
        githubFullIssuesPage(1, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (now()->getTimestamp() + 20)]),
        githubIssuesPage([githubIssue(101)], ['X-RateLimit-Remaining' => '29']),
    ], $history);

    expect(iterator_to_array($tracker->reconciliationCandidates(), false))->toHaveCount(101)
        ->and($history)->toHaveCount(2);

    Sleep::assertSlept(sleptSeconds(21), 1);
});

it('does not wait for a spent quota that resets beyond the wait cap', function () {
    $this->freezeTime();
    Sleep::fake();
    $history = [];

    $tracker = githubReconciliationTracker([
        githubFullIssuesPage(1, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (now()->getTimestamp() + 1800)]),
        githubRateLimitRefusal(1799),
    ], $history);

    expect(fn () => iterator_to_array($tracker->reconciliationCandidates(), false))
        ->toThrow(TrackerRateLimitExceededException::class, 'GitHub rate limit exceeded after 1 attempt(s)');

    Sleep::assertNeverSlept();
});

it('honours retry-after on a github secondary rate limit', function () {
    Sleep::fake();
    $history = [];

    $tracker = githubReconciliationTracker([
        new Response(429, ['Retry-After' => '45'], '{"message":"You have exceeded a secondary rate limit."}'),
        githubIssuesPage([githubIssue(101)]),
    ], $history);

    expect(iterator_to_array($tracker->reconciliationCandidates(), false))->toHaveCount(1);

    Sleep::assertSlept(sleptSeconds(45), 1);
});

it('does not retry a github listing refused for a reason other than the rate limit', function () {
    Sleep::fake();
    $history = [];

    $tracker = githubReconciliationTracker([
        new Response(403, [], '{"message":"Resource not accessible by personal access token"}'),
    ], $history);

    expect(fn () => iterator_to_array($tracker->reconciliationCandidates(), false))
        ->toThrow(ClientException::class);

    Sleep::assertNeverSlept();
});

it('fails instead of waiting when the github rate limit resets beyond the wait cap', function () {
    $this->freezeTime();
    Sleep::fake();
    $history = [];

    $tracker = githubReconciliationTracker([githubRateLimitRefusal(600)], $history);

    try {
        iterator_to_array($tracker->reconciliationCandidates(), false);
        $this->fail('Expected the listing to give up on the rate limit.');
    } catch (TrackerRateLimitExceededException $exception) {
        expect($exception->getMessage())->toContain('GitHub rate limit exceeded after 1 attempt(s)')
            ->and($exception->getMessage())->toContain('(in 601 s)')
            ->and($exception->resetsAt->getTimestamp())->toBe(now()->getTimestamp() + 601);
    }

    Sleep::assertNeverSlept();
});

it('gives up on a github listing still rate limited after its retries', function () {
    $this->freezeTime();
    Sleep::fake();
    $history = [];

    $tracker = githubReconciliationTracker([githubRateLimitRefusal(10), githubRateLimitRefusal(10), githubRateLimitRefusal(10)], $history);

    expect(fn () => iterator_to_array($tracker->reconciliationCandidates(), false))
        ->toThrow(TrackerRateLimitExceededException::class, 'GitHub rate limit exceeded after 3 attempt(s)');

    expect($history)->toHaveCount(3);
    Sleep::assertSlept(sleptSeconds(11), 2);
});

it('adds a markdown comment to a github issue', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(201, [], '{"id":1}')]));
    $stack->push(Middleware::history($history));

    $tracker = new GitHubTracker(app(Vault::class));
    injectGitHubClient($tracker, new GitHubClient('token', 'https://api.github.com', new Client(['handler' => $stack])));

    $tracker->addComment('octo-org/appsec-scout#101', "## Triage\n\nConfirmed **true positive**.");

    $request = $history[0]['request'];
    $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toEndWith('repos/octo-org/appsec-scout/issues/101/comments')
        ->and($payload)->toBe(['body' => "## Triage\n\nConfirmed **true positive**."]);
});
