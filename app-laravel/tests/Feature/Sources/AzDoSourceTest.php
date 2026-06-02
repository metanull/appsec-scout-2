<?php

use App\Credentials\Vault;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityEvent;
use App\Sources\AzDo\AzDoClient;
use App\Sources\AzDo\AzDoSource;
use App\Sources\Context\SourceContextFacts;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;
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

function injectAzDoClient(AzDoSource $source, AzDoClient $client): void
{
    $reflection = new ReflectionClass($source);
    $property = $reflection->getProperty('client');
    $property->setAccessible(true);
    $property->setValue($source, $client);
}

it('fetches systems, containers, and events from fixtures', function () {
    $http = new Client([
        'handler' => new MockHandler([
            new Response(200, [], azdoFixture('projects.json')),
            new Response(200, [], azdoFixture('repositories.json')),
            new Response(200, [], azdoFixture('repositories.json')),
        ]),
    ]);

    $advSec = new Client([
        'handler' => new MockHandler([
            new Response(200, [], azdoFixture('alerts-code.json')),
            new Response(200, [], azdoFixture('alerts-dependency.json')),
            new Response(200, [], azdoFixture('alerts-secret.json')),
            new Response(200, [], '{"count":0,"value":[]}'),
            new Response(200, [], '{"count":0,"value":[]}'),
            new Response(200, [], '{"count":0,"value":[]}'),
        ]),
    ]);

    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec);
    $source = new AzDoSource(app(Vault::class));
    injectAzDoClient($source, $client);

    $systems = iterator_to_array($source->fetchSystems());
    $containers = iterator_to_array($source->fetchContainers($systems[0]));
    $events = iterator_to_array($source->fetchEvents(null, $systems[0]));

    expect($systems)->toHaveCount(2)
        ->and($containers)->toHaveCount(2)
        ->and($events)->toHaveCount(7)
        ->and($events[0]->sourceSystemId)->toBe('project-001')
        ->and(SourceContextFacts::get($systems[0]->metadata ?? [], SourceContextFacts::AZDO_PROJECT_ID))->toBe('project-001')
        ->and(SourceContextFacts::get($containers[0]->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_ID))->toBe('repo-001')
        ->and(SourceContextFacts::get($containers[0]->metadata ?? [], SourceContextFacts::CODE_DEFAULT_BRANCH))->toBe('main');

    $dependency = collect($events)->first(fn ($event) => $event->type === EventType::Dependency);
    expect($dependency)->not->toBeNull()
        ->and(SourceContextFacts::get($dependency->metadata ?? [], SourceContextFacts::PACKAGE_NAME))->toBe('lodash')
        ->and(SourceContextFacts::get($dependency->metadata ?? [], SourceContextFacts::SECURITY_CVE))->toBe('CVE-2020-8203');

    $codeAlert = collect($events)->first(fn ($event) => $event->type === EventType::Vulnerability);
    expect($codeAlert)->not->toBeNull()
        ->and(SourceContextFacts::get($codeAlert->metadata ?? [], SourceContextFacts::SOURCE_ALERT_WEB_URL))->toBeString()
        ->and(SourceContextFacts::get($codeAlert->metadata ?? [], SourceContextFacts::CODE_COMMIT_SHA))->toBeString();
});

it('returns stable dependency fingerprint across re-fetches', function () {
    $singleRepoResponse = json_encode([
        'count' => 1,
        'value' => [
            [
                'id' => 'repo-001',
                'name' => 'backend-api',
                'webUrl' => 'https://dev.azure.com/testorg/SecurityProject/_git/backend-api',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $http = new Client([
        'handler' => new MockHandler([
            new Response(200, [], $singleRepoResponse),
            new Response(200, [], $singleRepoResponse),
        ]),
    ]);

    $advSec = new Client([
        'handler' => new MockHandler([
            new Response(200, [], '{"count":0,"value":[]}'),
            new Response(200, [], azdoFixture('alerts-dependency.json')),
            new Response(200, [], '{"count":0,"value":[]}'),
            new Response(200, [], '{"count":0,"value":[]}'),
            new Response(200, [], azdoFixture('alerts-dependency.json')),
            new Response(200, [], '{"count":0,"value":[]}'),
        ]),
    ]);

    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec);
    $source = new AzDoSource(app(Vault::class));
    injectAzDoClient($source, $client);

    $system = new SystemDto('project-001', 'SecurityProject');

    $first = array_values(array_filter(
        iterator_to_array($source->fetchEvents(null, $system)),
        fn ($event) => $event->type === EventType::Dependency,
    ));

    $second = array_values(array_filter(
        iterator_to_array($source->fetchEvents(null, $system)),
        fn ($event) => $event->type === EventType::Dependency,
    ));

    expect($first)->toHaveCount(4)->and($second)->toHaveCount(4)
        ->and($first[0]->fingerprint)->toBe($second[0]->fingerprint)
        ->and($first[1]->fingerprint)->toBe($second[1]->fingerprint);
});

it('hydrates sparse alert list items before building event dtos', function () {
    $singleRepoResponse = json_encode([
        'count' => 1,
        'value' => [
            [
                'id' => 'repo-001',
                'name' => 'backend-api',
                'webUrl' => 'https://dev.azure.com/testorg/SecurityProject/_git/backend-api',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $detail = json_decode(azdoFixture('alerts-code.json'), true, 512, JSON_THROW_ON_ERROR)['value'][0];
    $sparseList = json_encode([
        'count' => 1,
        'value' => [[
            'alertId' => $detail['alertId'],
            'alertType' => $detail['alertType'],
            'severity' => $detail['severity'],
            'state' => $detail['state'],
            'title' => $detail['title'],
        ]],
    ], JSON_THROW_ON_ERROR);

    $http = new Client([
        'handler' => new MockHandler([
            new Response(200, [], $singleRepoResponse),
        ]),
    ]);

    $advSec = new Client([
        'handler' => new MockHandler([
            new Response(200, [], $sparseList),
            new Response(200, [], json_encode($detail, JSON_THROW_ON_ERROR)),
            new Response(200, [], '{"count":0,"value":[]}'),
            new Response(200, [], '{"count":0,"value":[]}'),
        ]),
    ]);

    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec);
    $source = new AzDoSource(app(Vault::class));
    injectAzDoClient($source, $client);

    $events = iterator_to_array($source->fetchEvents(null, new SystemDto('project-001', 'SecurityProject')));

    expect($events)->toHaveCount(1)
        ->and($events[0]->description)->not->toBeNull()
        ->and($events[0]->url)->not->toBeNull()
        ->and($events[0]->filePath)->not->toBeNull()
        ->and($events[0]->versionControlUrl)->not->toBeNull();
});

it('enriches secret event with occurrences list', function () {
    $http = new Client(['handler' => new MockHandler]);
    $advSec = new Client([
        'handler' => new MockHandler([
            new Response(200, [], azdoFixture('secret-instances.json')),
        ]),
    ]);

    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec);
    $source = new AzDoSource(app(Vault::class));
    injectAzDoClient($source, $client);

    $event = SecurityEvent::factory()->secret()->create([
        'source_id' => 'azdo',
        'source_event_id' => '3001',
        'metadata' => [
            'sourceProjectId' => 'project-001',
            'sourceRepoId' => 'repo-001',
        ],
    ]);

    $enriched = $source->enrichEvent($event);

    if (! $enriched instanceof EventDto) {
        throw new RuntimeException('Expected enriched AzDo secret event dto.');
    }

    expect($enriched->metadata['occurrences'] ?? null)->toBeArray()
        ->and($enriched->metadata['occurrences'] ?? null)->toHaveCount(2);
});

it('pushes dismissed state with expected payload shape', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{}'),
    ]));
    $stack->push(Middleware::history($history));

    $http = new Client(['handler' => new MockHandler]);
    $advSec = new Client(['handler' => $stack]);

    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec);
    $source = new AzDoSource(app(Vault::class));
    injectAzDoClient($source, $client);

    $event = SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'source_event_id' => '3001',
        'state' => EventState::Open,
        'pending_state' => EventState::Dismissed,
        'pending_comment' => 'False positive after review.',
        'metadata' => [
            'sourceProjectId' => 'project-001',
            'sourceRepoId' => 'repo-001',
            'dismissalReason' => 'falsePositive',
        ],
    ]);

    $result = $source->pushEventState($event);

    expect($result->ok)->toBeTrue()
        ->and($history)->toHaveCount(1);

    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($body)->toBe([
        'state' => 'dismissed',
        'dismissalReason' => 'falsePositive',
        'dismissalMessage' => 'False positive after review.',
    ]);
});
