<?php

use App\Credentials\Vault;
use App\Trackers\Dto\CreateWorkItemRequest;
use App\Trackers\Dto\UpdateWorkItemRequest;
use App\Trackers\Jira\JiraClient;
use App\Trackers\Jira\JiraTracker;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

if (! function_exists('jiraFixture')) {
    function jiraFixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/Trackers/Jira/{$name}"));
    }
}

function injectJiraClient(JiraTracker $tracker, JiraClient $client): void
{
    $reflection = new ReflectionClass($tracker);
    $property = $reflection->getProperty('client');
    $property->setAccessible(true);
    $property->setValue($tracker, $client);
}

it('creates a jira issue with labels and parent linking', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(201, [], jiraFixture('create-issue-response.json')),
    ]));
    $stack->push(Middleware::history($history));

    $tracker = new JiraTracker(app(Vault::class));
    injectJiraClient($tracker, new JiraClient(
        'https://example.atlassian.net',
        'ops@example.test',
        'token',
        new Client(['handler' => $stack]),
    ));

    $workItem = $tracker->createWorkItem(new CreateWorkItemRequest(
        projectKey: 'APP',
        itemType: 'Bug',
        title: 'Grouped secret findings',
        description: "### Description\n\nRotated secrets are required.",
        labels: ['security', 'appsec-scout', 'github', 'critical', 'secret'],
        priority: 'High',
        assigneeId: 'acct-1',
        parentId: 'APP-7',
    ));

    expect($workItem->id)->toBe('APP-101')
        ->and($workItem->url)->toBe('https://example.atlassian.net/browse/APP-101')
        ->and($history)->toHaveCount(1);

    $payload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['fields']['parent']['key'])->toBe('APP-7')
        ->and($payload['fields']['labels'])->toBe(['security', 'appsec-scout', 'github', 'critical', 'secret'])
        ->and($payload['fields']['priority']['name'])->toBe('High')
        ->and($payload['fields']['assignee']['accountId'])->toBe('acct-1')
        ->and($payload['fields']['description']['type'])->toBe('doc');
});

it('gets an existing jira issue', function () {
    $tracker = new JiraTracker(app(Vault::class));
    injectJiraClient($tracker, new JiraClient(
        'https://example.atlassian.net',
        'ops@example.test',
        'token',
        new Client(['handler' => new MockHandler([
            new Response(200, [], jiraFixture('get-issue-response.json')),
        ])]),
    ));

    $workItem = $tracker->getWorkItem('APP-101');

    expect($workItem)->not->toBeNull()
        ->and($workItem?->id)->toBe('APP-101')
        ->and($workItem?->title)->toBe('Grouped secret findings')
        ->and($workItem?->state)->toBe('In Progress')
        ->and($workItem?->parentId)->toBe('APP-7')
        ->and($workItem?->description)->toContain('This issue was created by AppSec Scout')
        ->and($workItem?->description)->toContain('https://appsec.example.com/alerts/42');
});

it('updates a jira issue and transitions its state', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(204, [], ''),
        new Response(200, [], jiraFixture('transitions-response.json')),
        new Response(204, [], ''),
        new Response(200, [], jiraFixture('updated-issue-response.json')),
    ]));
    $stack->push(Middleware::history($history));

    $tracker = new JiraTracker(app(Vault::class));
    injectJiraClient($tracker, new JiraClient(
        'https://example.atlassian.net',
        'ops@example.test',
        'token',
        new Client(['handler' => $stack]),
    ));

    $workItem = $tracker->updateWorkItem('APP-101', new UpdateWorkItemRequest(
        title: 'Grouped secret findings (remediating)',
        description: "### Description\n\nThe team is actively remediating this issue.",
        state: 'Resolved',
        labels: ['security', 'appsec-scout', 'resolved'],
        priority: 'Highest',
        assigneeId: 'acct-2',
    ));

    expect($workItem->title)->toBe('Grouped secret findings (remediating)')
        ->and($workItem->state)->toBe('Resolved')
        ->and($history)->toHaveCount(4);

    $updatePayload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $transitionPayload = json_decode((string) $history[2]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($updatePayload['fields']['labels'])->toBe(['security', 'appsec-scout', 'resolved'])
        ->and($transitionPayload)->toBe(['transition' => ['id' => '31']]);
});

it('tests jira connectivity through the tracker contract', function () {
    app(Vault::class)->set('jira.host', null, 'https://example.atlassian.net');
    app(Vault::class)->set('jira.email', null, 'ops@example.test');
    app(Vault::class)->set('jira.api_token', null, 'token');

    $tracker = new JiraTracker(app(Vault::class));
    injectJiraClient($tracker, new JiraClient(
        'https://example.atlassian.net',
        'ops@example.test',
        'token',
        new Client(['handler' => new MockHandler([
            new Response(200, [], jiraFixture('myself-response.json')),
        ])]),
    ));

    expect($tracker->testConnection()->ok)->toBeTrue();
});

it('maps jira projects with consistent key and name fields', function () {
    $tracker = new JiraTracker(app(Vault::class));
    injectJiraClient($tracker, new JiraClient(
        'https://example.atlassian.net',
        'ops@example.test',
        'token',
        new Client(['handler' => new MockHandler([
            new Response(200, [], json_encode([
                'startAt' => 0,
                'maxResults' => 50,
                'isLast' => true,
                'values' => [
                    [
                        'key' => 'APP',
                        'name' => 'Application',
                        'self' => 'https://example.atlassian.net/rest/api/3/project/10001',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ])]),
    ));

    $projects = iterator_to_array($tracker->fetchProjects(), false);

    expect($projects)->toHaveCount(1)
        ->and($projects[0]->key)->toBe('APP')
        ->and($projects[0]->name)->toBe('Application');
});

it('fetches reconciliation candidates from jira with adf extraction and pagination', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'isLast' => false,
            'nextPageToken' => 'page-2',
            'issues' => [[
                'key' => 'APP-101',
                'fields' => [
                    'summary' => 'Security finding one',
                    'status' => ['name' => 'Open'],
                    'labels' => ['security'],
                    'description' => [
                        'type' => 'doc',
                        'content' => [[
                            'type' => 'paragraph',
                            'content' => [[
                                'type' => 'text',
                                'text' => 'Alert link',
                                'marks' => [[
                                    'type' => 'link',
                                    'attrs' => ['href' => 'https://example.com/finding/101'],
                                ]],
                            ]],
                        ]],
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode([
            'isLast' => true,
            'issues' => [[
                'key' => 'APP-102',
                'fields' => [
                    'summary' => 'Security finding two',
                    'status' => ['name' => 'Open'],
                    'labels' => ['vulnerability'],
                    'description' => [
                        'type' => 'doc',
                        'content' => [[
                            'type' => 'paragraph',
                            'content' => [[
                                'type' => 'inlineCard',
                                'attrs' => ['url' => 'https://example.com/finding/102'],
                            ]],
                        ]],
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($history));

    $tracker = new JiraTracker(app(Vault::class));
    injectJiraClient($tracker, new JiraClient(
        'https://example.atlassian.net',
        'ops@example.test',
        'token',
        new Client(['handler' => $stack]),
    ));

    $candidates = iterator_to_array($tracker->reconciliationCandidates('APP'), false);

    expect($candidates)->toHaveCount(2)
        ->and($candidates[0]->workItemId)->toBe('APP-101')
        ->and($candidates[0]->extractedUrls)->toContain('https://example.com/finding/101')
        ->and($candidates[1]->workItemId)->toBe('APP-102')
        ->and($candidates[1]->extractedUrls)->toContain('https://example.com/finding/102')
        ->and($history)->toHaveCount(2);

    parse_str($history[1]['request']->getUri()->getQuery(), $secondPageQuery);

    expect($secondPageQuery['nextPageToken'] ?? null)->toBe('page-2');
});

it('returns empty reconciliation candidates for empty jira project key', function () {
    $tracker = new JiraTracker(app(Vault::class));
    injectJiraClient($tracker, new JiraClient(
        'https://example.atlassian.net',
        'ops@example.test',
        'token',
        new Client(['handler' => new MockHandler([])]),
    ));

    expect(iterator_to_array($tracker->reconciliationCandidates(''), false))->toBe([]);
});

it('searches work items matching by key and by summary', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], jiraFixture('search-issues-response.json')),
    ]));
    $stack->push(Middleware::history($history));

    $tracker = new JiraTracker(app(Vault::class));
    injectJiraClient($tracker, new JiraClient(
        'https://example.atlassian.net',
        'ops@example.test',
        'token',
        new Client(['handler' => $stack]),
    ));

    $results = iterator_to_array($tracker->searchWorkItems('APP', 'APP-101', 20), false);

    expect($results)->toHaveCount(1)
        ->and($results[0]->id)->toBe('APP-101');

    parse_str($history[0]['request']->getUri()->getQuery(), $params);
    $jql = $params['jql'] ?? '';

    expect($jql)->toContain('key = "APP-101"')
        ->and($jql)->toContain('summary ~ "APP-101"');
});

it('fetches available priorities from jira', function () {
    $tracker = new JiraTracker(app(Vault::class));
    injectJiraClient($tracker, new JiraClient(
        'https://example.atlassian.net',
        'ops@example.test',
        'token',
        new Client(['handler' => new MockHandler([
            new Response(200, [], jiraFixture('priorities-response.json')),
        ])]),
    ));

    $priorities = iterator_to_array($tracker->fetchPriorities('APP'), false);

    expect($priorities)->toBe(['Blocker', 'Critical', 'Major', 'Medium', 'Minor']);
});
