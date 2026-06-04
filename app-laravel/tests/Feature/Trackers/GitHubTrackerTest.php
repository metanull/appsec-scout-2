<?php

use App\Credentials\Vault;
use App\Trackers\Dto\CreateWorkItemRequest;
use App\Trackers\Dto\UpdateWorkItemRequest;
use App\Trackers\GitHub\GitHubClient;
use App\Trackers\GitHub\GitHubTracker;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

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

it('fetches reconciliation candidates from github with markdown extraction and pagination', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'total_count' => 2,
            'items' => [[
                'number' => 101,
                'title' => 'Security issue one',
                'state' => 'open',
                'html_url' => 'https://github.com/octo-org/appsec-scout/issues/101',
                'labels' => [['name' => 'security']],
                'body' => 'See [Alert](https://example.com/finding/101)',
            ]],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode([
            'total_count' => 2,
            'items' => [[
                'number' => 102,
                'title' => 'Security issue two',
                'state' => 'open',
                'html_url' => 'https://github.com/octo-org/appsec-scout/issues/102',
                'labels' => [['name' => 'security']],
                'body' => 'Repository root https://dev.azure.com/acme/proj/_git/repo',
            ]],
        ], JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($history));

    $tracker = new GitHubTracker(app(Vault::class));
    injectGitHubClient($tracker, new GitHubClient(
        'token',
        'https://api.github.com',
        new Client(['handler' => $stack]),
    ));

    $candidates = iterator_to_array($tracker->reconciliationCandidates('octo-org/appsec-scout'), false);

    expect($candidates)->toHaveCount(2)
        ->and($candidates[0]->workItemId)->toBe('octo-org/appsec-scout#101')
        ->and($candidates[0]->extractedUrls)->toContain('https://example.com/finding/101')
        ->and($candidates[1]->workItemId)->toBe('octo-org/appsec-scout#102')
        ->and($history)->toHaveCount(2);

    parse_str($history[1]['request']->getUri()->getQuery(), $secondPageQuery);

    expect((int) ($secondPageQuery['page'] ?? 0))->toBe(2);
});

it('returns empty reconciliation candidates for empty github project key', function () {
    $tracker = new GitHubTracker(app(Vault::class));
    injectGitHubClient($tracker, new GitHubClient(
        'token',
        'https://api.github.com',
        new Client(['handler' => new MockHandler([])]),
    ));

    expect(iterator_to_array($tracker->reconciliationCandidates(''), false))->toBe([]);
});
