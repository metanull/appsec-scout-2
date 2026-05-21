<?php

use App\Triage\CodesearchClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

it('sends the expected Azure DevOps code search request', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['count' => 1, 'results' => []], JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($history));

    $client = new CodesearchClient('testorg', 'test-pat', new Client(['handler' => $stack]));

    $payload = $client->search('openssl', ['Project' => ['SecurityProject']]);

    expect($payload['count'])->toBe(1)
        ->and($history)->toHaveCount(1);

    $request = $history[0]['request'];
    $uri = (string) $request->getUri();
    parse_str(parse_url($uri, PHP_URL_QUERY) ?: '', $query);

    expect($request->getMethod())->toBe('POST')
        ->and($query['api-version'] ?? null)->toBe('7.1');

    $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($body)->toBe([
        'searchText' => 'openssl',
        '$top' => 100,
        '$skip' => 0,
        'filters' => ['Project' => ['SecurityProject']],
    ]);
});
