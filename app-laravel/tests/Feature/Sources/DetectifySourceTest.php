<?php

use App\Credentials\Vault;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Sources\Detectify\DetectifyClient;
use App\Sources\Detectify\DetectifySource;
use App\Sources\Dto\SystemDto;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function injectDetectifyClient(DetectifySource $source, DetectifyClient $client): void
{
    $reflection = new ReflectionClass($source);
    $property = $reflection->getProperty('client');
    $property->setAccessible(true);
    $property->setValue($source, $client);
}

it('fetches systems and findings from fixtures', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], (string) file_get_contents(base_path('tests/Fixtures/Detectify/domains.json'))),
        new Response(200, [], (string) file_get_contents(base_path('tests/Fixtures/Detectify/findings-domain-001.json'))),
    ]));
    $stack->push(Middleware::history($history));

    $client = new DetectifyClient('api-key', 'https://api.detectify.com', new Client([
        'handler' => $stack,
    ]));

    $source = new DetectifySource(app(Vault::class));
    injectDetectifyClient($source, $client);

    $systems = iterator_to_array($source->fetchSystems());
    $events = iterator_to_array($source->fetchEvents(null, new SystemDto('domain-001', 'example.com')));

    expect($systems)->toHaveCount(2)
        ->and($events)->toHaveCount(2)
        ->and((string) $history[0]['request']->getUri())->toContain('rest/v2/assets/')
        ->and((string) $history[1]['request']->getUri())->toContain('rest/v2/vulnerabilities/')
        ->and($history[0]['request']->getHeaderLine('X-Detectify-Key'))->toBe('api-key');
});

it('pushes event state with expected patch body', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{}'),
    ]));
    $stack->push(Middleware::history($history));

    $client = new DetectifyClient('api-key', 'https://api.detectify.com', new Client(['handler' => $stack]));

    $source = new DetectifySource(app(Vault::class));
    injectDetectifyClient($source, $client);

    $event = SecurityEvent::factory()->create([
        'source_id' => 'detectify',
        'source_event_id' => 'finding-001',
        'pending_state' => EventState::Resolved,
        'pending_comment' => 'Patched in release 2026.05',
        'metadata' => [
            'domainToken' => 'domain-001',
        ],
    ]);

    $result = $source->pushEventState($event);

    expect($result->ok)->toBeTrue()
        ->and($history)->toHaveCount(1);

    expect((string) $history[0]['request']->getUri())->toContain('rest/v2/vulnerabilities/uuid/finding-001/setfixedstatus/')
        ->and($history[0]['request']->getMethod())->toBe('POST');
});
