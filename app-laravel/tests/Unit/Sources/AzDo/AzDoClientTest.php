<?php

use App\Sources\AzDo\AzDoClient;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

if (! function_exists('azdoFixture')) {
    function azdoFixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/AzDo/{$name}"));
    }
}

it('fetches systems from projects fixture', function () {
    $http = new Client([
        'handler' => new MockHandler([
            new Response(200, [], azdoFixture('projects.json')),
        ]),
    ]);

    $advSec = new Client([
        'handler' => new MockHandler,
    ]);

    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec);

    $systems = $client->listProjects();

    expect($systems)->toHaveCount(2)
        ->and($systems[0]->id)->toBe('project-001')
        ->and($systems[0]->name)->toBe('SecurityProject');
});

it('fetches repositories from fixture', function () {
    $http = new Client([
        'handler' => new MockHandler([
            new Response(200, [], azdoFixture('repositories.json')),
        ]),
    ]);

    $advSec = new Client([
        'handler' => new MockHandler,
    ]);

    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec);

    $repos = $client->listRepositories('project-001');

    expect($repos)->toHaveCount(2)
        ->and($repos[0]->id)->toBe('repo-001')
        ->and($repos[0]->name)->toBe('backend-api');
});

it('fetches code alerts and sends criteria.modifiedSince query for incremental mode', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], azdoFixture('alerts-code.json')),
    ]));
    $stack->push(Middleware::history($history));

    $http = new Client([
        'handler' => new MockHandler,
    ]);

    $advSec = new Client([
        'handler' => $stack,
    ]);

    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec);

    $since = Carbon::parse('2026-02-01T00:00:00Z');
    $alerts = $client->listAlerts('project-001', 'repo-001', 'code', $since);

    expect($alerts)->toHaveCount(2)
        ->and($history)->toHaveCount(1);

    $uri = (string) $history[0]['request']->getUri();
    parse_str(parse_url($uri, PHP_URL_QUERY) ?: '', $query);

    expect($query['alertType'] ?? null)->toBe('code')
        ->and(isset($query['criteria_modifiedSince']) || isset($query['criteria.modifiedSince']))->toBeTrue();
});

it('updates alert with expected patch request body', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{}'),
    ]));
    $stack->push(Middleware::history($history));

    $http = new Client(['handler' => new MockHandler]);
    $advSec = new Client(['handler' => $stack]);

    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec);

    $client->updateAlert('project-001', 'repo-001', 3001, [
        'state' => 'dismissed',
        'dismissalReason' => 'falsePositive',
        'dismissalMessage' => 'Reviewed by team',
    ]);

    expect($history)->toHaveCount(1);

    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('PATCH');

    $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($payload)->toBe([
        'state' => 'dismissed',
        'dismissalReason' => 'falsePositive',
        'dismissalMessage' => 'Reviewed by team',
    ]);
});
