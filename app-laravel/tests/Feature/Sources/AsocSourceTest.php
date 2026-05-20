<?php

use App\Credentials\Vault;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Sources\Asoc\AsocClient;
use App\Sources\Asoc\AsocSource;
use App\Sources\Dto\SystemDto;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    cache()->forget('asoc.token');
});

function injectAsocClient(AsocSource $source, AsocClient $client): void
{
    $reflection = new ReflectionClass($source);
    $property = $reflection->getProperty('client');
    $property->setAccessible(true);
    $property->setValue($source, $client);
}

it('fetches systems containers and typed events from fixtures', function () {
    $client = new AsocClient('key-id', 'key-secret', 'https://cloud.appscan.com', new Client([
        'handler' => new MockHandler([
            new Response(200, [], '{"Token":"token-1"}'),
            new Response(200, [], (string) file_get_contents(base_path('tests/Fixtures/Asoc/apps-page-1.json'))),
            new Response(200, [], (string) file_get_contents(base_path('tests/Fixtures/Asoc/scans-app-001.json'))),
            new Response(200, [], (string) file_get_contents(base_path('tests/Fixtures/Asoc/issues-variants.json'))),
        ]),
    ]));

    $source = new AsocSource(app(Vault::class));
    injectAsocClient($source, $client);

    $systems = iterator_to_array($source->fetchSystems());
    $containers = iterator_to_array($source->fetchContainers(new SystemDto('app-001', 'Payments API')));
    $events = iterator_to_array($source->fetchEvents(null, new SystemDto('app-001', 'Payments API')));

    expect($systems)->toHaveCount(1)
        ->and($containers)->toHaveCount(1)
        ->and($events)->toHaveCount(5);
});

it('pushes event state with expected body shape and odata filter', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"Token":"token-1"}'),
        new Response(200, [], '{}'),
    ]));
    $stack->push(Middleware::history($history));

    $client = new AsocClient('key-id', 'key-secret', 'https://cloud.appscan.com', new Client(['handler' => $stack]));

    $source = new AsocSource(app(Vault::class));
    injectAsocClient($source, $client);

    $event = SecurityEvent::factory()->create([
        'source_id' => 'asoc',
        'source_event_id' => '1005',
        'state' => EventState::Open,
        'pending_state' => EventState::Dismissed,
        'pending_comment' => 'False positive',
        'software_system_id' => SoftwareSystem::factory()->create([
            'source_system_id' => 'app-001',
            'source_id' => 'asoc',
        ])->id,
    ]);

    $result = $source->pushEventState($event->fresh(['softwareSystem']));

    expect($result->ok)->toBeTrue()
        ->and($history)->toHaveCount(2);

    $payload = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toBe([
        'Status' => 'Noise',
        'Comment' => 'False positive',
        'odataFilter' => 'Id eq 1005',
    ]);
});

it('enriches remediation article, sanitizes html, and uses cache on second call', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"Token":"token-1"}'),
        new Response(200, ['Content-Type' => 'text/html'], (string) file_get_contents(base_path('tests/Fixtures/Asoc/article-general-dast.html'))),
        new Response(200, ['Content-Type' => 'text/html'], (string) file_get_contents(base_path('tests/Fixtures/Asoc/article-focused-missing-hsts.html'))),
    ]));
    $stack->push(Middleware::history($history));

    $client = new AsocClient('key-id', 'key-secret', 'https://cloud.appscan.com', new Client(['handler' => $stack]));

    $source = new AsocSource(app(Vault::class));
    injectAsocClient($source, $client);

    $event = SecurityEvent::factory()->vulnerability()->create([
        'source_id' => 'asoc',
        'source_event_id' => '1004',
        'metadata' => [
            'issueTypeId' => 'dast.01',
            'language' => 'en',
            'apiVulnName' => 'Missing HSTS',
        ],
    ]);

    $first = $source->enrichEvent($event);
    $second = $source->enrichEvent($event->fresh());

    expect($first)->not->toBeNull()
        ->and($first->remediation)->toContain('Missing HSTS')
        ->and($first->remediation)->not->toContain('<script')
        ->and($first->remediation)->not->toContain('onclick')
        ->and($second)->not->toBeNull()
        ->and($history)->toHaveCount(3);
});
