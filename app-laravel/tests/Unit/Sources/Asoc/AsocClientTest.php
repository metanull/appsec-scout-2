<?php

use App\Sources\Asoc\AsocClient;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    cache()->forget('asoc.token');
});

it('caches auth token and reuses it', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"Token":"token-1"}'),
        new Response(200, [], '{"Items":[]}'),
        new Response(200, [], '{"Items":[]}'),
    ]));
    $stack->push(Middleware::history($history));

    $client = new AsocClient('key-id', 'key-secret', 'https://cloud.appscan.com', new Client(['handler' => $stack]));

    $client->listApplications();
    $client->listApplications();

    expect($history)->toHaveCount(3)
        ->and((string) $history[0]['request']->getUri())->toContain('ApiKeyLogin')
        ->and((string) $history[1]['request']->getUri())->toContain('api/v4/Apps')
        ->and((string) $history[2]['request']->getUri())->toContain('api/v4/Apps');
});

it('evicts token and retries once on unauthorized response', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"Token":"token-1"}'),
        new Response(401, [], '{"message":"expired"}'),
        new Response(200, [], '{"Token":"token-2"}'),
        new Response(200, [], '{"Items":[]}'),
    ]));
    $stack->push(Middleware::history($history));

    $client = new AsocClient('key-id', 'key-secret', 'https://cloud.appscan.com', new Client(['handler' => $stack]));

    $apps = $client->listApplications();

    expect($apps)->toBeArray()->toHaveCount(0)
        ->and($history)->toHaveCount(4);
});

it('paginates through two pages', function () {
    $firstPageItems = [];

    for ($i = 1; $i <= 100; $i++) {
        $firstPageItems[] = ['Id' => 'app-' . $i, 'Name' => 'App ' . $i];
    }

    $secondPageItems = [];

    for ($i = 101; $i <= 120; $i++) {
        $secondPageItems[] = ['Id' => 'app-' . $i, 'Name' => 'App ' . $i];
    }

    $client = new AsocClient('key-id', 'key-secret', 'https://cloud.appscan.com', new Client([
        'handler' => new MockHandler([
            new Response(200, [], '{"Token":"token-1"}'),
            new Response(200, [], json_encode(['Items' => $firstPageItems], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['Items' => $secondPageItems], JSON_THROW_ON_ERROR)),
        ]),
    ]));

    $apps = $client->listApplications();

    expect($apps)->toHaveCount(120)
        ->and($apps[0]['Id'])->toBe('app-1')
        ->and($apps[119]['Id'])->toBe('app-120');
});

it('adds modified since filter when listing issues incrementally', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"Token":"token-1"}'),
        new Response(200, [], '{"Items":[]}'),
    ]));
    $stack->push(Middleware::history($history));

    $client = new AsocClient('key-id', 'key-secret', 'https://cloud.appscan.com', new Client(['handler' => $stack]));

    $client->listIssues('app-001', Carbon::parse('2026-02-01T00:00:00Z')->toDateTime());

    $uri = (string) $history[1]['request']->getUri();

    expect($uri)->toContain('%24filter=LastUpdated')
        ->and($uri)->toContain('ge')
        ->and($uri)->toContain('%27');
});

it('honors proxy options from outbound http factory', function () {
    config([
        'proxy.http_proxy' => 'http://proxy.local:8080',
        'proxy.https_proxy' => 'http://proxy.local:8080',
        'proxy.no_proxy' => 'localhost,127.0.0.1',
        'proxy.verify' => true,
    ]);

    $client = new AsocClient('key-id', 'key-secret');

    $reflection = new ReflectionClass($client);
    $property = $reflection->getProperty('http');
    $property->setAccessible(true);
    $httpClient = $property->getValue($client);

    $proxy = $httpClient->getConfig('proxy');

    expect($proxy)->toBeArray()
        ->and($proxy['https'])->toBe('http://proxy.local:8080');
});
