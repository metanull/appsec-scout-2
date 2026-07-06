<?php

use App\Assets\DependencyTrack\DependencyTrackClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

it('uploads a bom with the expected request shape', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{}'),
    ]));
    $stack->push(Middleware::history($history));

    $client = new DependencyTrackClient('dtrack-api-key', 'http://dependencytrack-apiserver:8080', new Client([
        'handler' => $stack,
    ]));

    $client->uploadBom('backend-api', 'latest', '{"bomFormat":"CycloneDX","components":[]}');

    expect($history)->toHaveCount(1);

    $request = $history[0]['request'];

    expect($request->getMethod())->toBe('PUT')
        ->and((string) $request->getUri())->toContain('api/v1/bom')
        ->and($request->getHeaderLine('X-Api-Key'))->toBe('dtrack-api-key');

    $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($body)->toBe([
        'projectName' => 'backend-api',
        'projectVersion' => 'latest',
        'autoCreate' => true,
        'bom' => base64_encode('{"bomFormat":"CycloneDX","components":[]}'),
    ]);
});
