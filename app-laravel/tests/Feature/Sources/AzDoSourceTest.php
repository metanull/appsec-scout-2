<?php

use App\Credentials\Vault;
use App\Models\Enums\AzDoDismissalReason;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityEvent;
use App\Sources\AzDo\AzDoClient;
use App\Sources\AzDo\AzDoSource;
use App\Sources\Context\SourceContextFacts;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;
use App\Sync\EnrichAzDoSecretJob;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
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
            new Response(200, [], '{"count":0,"value":[]}'),  // repo-001: license
            new Response(200, [], '{"count":0,"value":[]}'),  // repo-002: code
            new Response(200, [], '{"count":0,"value":[]}'),  // repo-002: dependency
            new Response(200, [], '{"count":0,"value":[]}'),  // repo-002: secret
            new Response(200, [], '{"count":0,"value":[]}'),  // repo-002: license
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
            new Response(200, [], '{"count":0,"value":[]}'),              // fetch 1: code
            new Response(200, [], azdoFixture('alerts-dependency.json')), // fetch 1: dependency
            new Response(200, [], '{"count":0,"value":[]}'),              // fetch 1: secret
            new Response(200, [], '{"count":0,"value":[]}'),              // fetch 1: license
            new Response(200, [], '{"count":0,"value":[]}'),              // fetch 2: code
            new Response(200, [], azdoFixture('alerts-dependency.json')), // fetch 2: dependency
            new Response(200, [], '{"count":0,"value":[]}'),              // fetch 2: secret
            new Response(200, [], '{"count":0,"value":[]}'),              // fetch 2: license
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

it('fetchEvents yields events from list data without making individual getAlert calls', function () {
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

    // Sparse list response — only the fields the real API returns from the list endpoint.
    // fetchEvents() must NOT make individual getAlert() calls; any extra mock response
    // consumed would cause a "no more handlers" exception, proving the test.
    $sparseList = json_encode([
        'count' => 1,
        'value' => [[
            'alertId' => 1001,
            'alertType' => 'code',
            'severity' => 'high',
            'state' => 'active',
            'title' => 'SQL injection risk',
            'tools' => [['name' => 'AdvancedSecurity-Code-Scanning', 'version' => '1.0']],
        ]],
    ], JSON_THROW_ON_ERROR);

    $http = new Client([
        'handler' => new MockHandler([new Response(200, [], $singleRepoResponse)]),
    ]);

    $advSec = new Client([
        'handler' => new MockHandler([
            new Response(200, [], $sparseList),              // code list
            new Response(200, [], '{"count":0,"value":[]}'), // dependency
            new Response(200, [], '{"count":0,"value":[]}'), // secret
            new Response(200, [], '{"count":0,"value":[]}'), // license
            // No individual getAlert response queued — consuming one would throw
        ]),
    ]);

    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec);
    $source = new AzDoSource(app(Vault::class));
    injectAzDoClient($source, $client);

    $events = iterator_to_array($source->fetchEvents(null, new SystemDto('project-001', 'SecurityProject')));

    expect($events)->toHaveCount(1)
        ->and($events[0]->sourceEventId)->toBe('1001')
        ->and($events[0]->title)->toBe('SQL injection risk');
});

it('enrichmentJobFor returns a job only for secret events', function () {
    $source = new AzDoSource(app(Vault::class));

    $secretEvent = SecurityEvent::factory()->secret()->create([
        'source_id' => 'azdo',
        'source_event_id' => '3001',
        'metadata' => ['sourceProjectId' => 'proj-1', 'sourceRepoId' => 'repo-1'],
    ]);

    $codeEvent = SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'source_event_id' => '1001',
        'type' => EventType::Vulnerability,
        'metadata' => ['sourceProjectId' => 'proj-1', 'sourceRepoId' => 'repo-1'],
    ]);

    $depEvent = SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'source_event_id' => '2001',
        'type' => EventType::Dependency,
        'metadata' => ['sourceProjectId' => 'proj-1', 'sourceRepoId' => 'repo-1'],
    ]);

    $secretJob = $source->enrichmentJobFor('azdo', $secretEvent);
    $codeJob = $source->enrichmentJobFor('azdo', $codeEvent);
    $depJob = $source->enrichmentJobFor('azdo', $depEvent);

    expect($secretJob)->toBeInstanceOf(EnrichAzDoSecretJob::class)
        ->and($secretJob->sourceId)->toBe('azdo')
        ->and($secretJob->eventId)->toBe($secretEvent->id)
        ->and($secretJob->projectId)->toBe('proj-1')
        ->and($secretJob->repoId)->toBe('repo-1')
        ->and($secretJob->alertId)->toBe(3001)
        ->and($codeJob)->toBeNull()
        ->and($depJob)->toBeNull();
});

it('enrichmentJobFor returns null when secret event is missing project or repo metadata', function () {
    $source = new AzDoSource(app(Vault::class));

    $noMeta = SecurityEvent::factory()->secret()->create([
        'source_id' => 'azdo',
        'source_event_id' => '3002',
        'metadata' => [],
    ]);

    expect($source->enrichmentJobFor('azdo', $noMeta))->toBeNull();
});

it('list alert requests do not include expand parameter to avoid API silent truncation', function () {
    $singleRepoResponse = json_encode([
        'count' => 1,
        'value' => [['id' => 'repo-001', 'name' => 'backend-api', 'webUrl' => 'https://dev.azure.com/testorg/SecurityProject/_git/backend-api']],
    ], JSON_THROW_ON_ERROR);

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"count":0,"value":[]}'),
        new Response(200, [], '{"count":0,"value":[]}'),
        new Response(200, [], '{"count":0,"value":[]}'),
        new Response(200, [], '{"count":0,"value":[]}'),
    ]));
    $stack->push(Middleware::history($history));

    $http = new Client(['handler' => new MockHandler([new Response(200, [], $singleRepoResponse)])]);
    $advSec = new Client(['handler' => $stack]);

    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec);
    $source = new AzDoSource(app(Vault::class));
    injectAzDoClient($source, $client);

    iterator_to_array($source->fetchEvents(null, new SystemDto('project-001', 'SecurityProject')));

    // The AzDO API silently truncates results when expand=1 is used on the list endpoint,
    // dropping alerts without providing a continuation token. The list query must not pass expand.
    foreach ($history as $transaction) {
        $uri = (string) $transaction['request']->getUri();
        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);
        expect($query)->not->toHaveKey('expand', 'list alerts request must not include expand to prevent silent result truncation');
    }
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

/**
 * Pushes the event through an AzDoSource whose Advanced Security client records the request,
 * and returns the decoded PATCH body.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function pushAzDoDismissal(array $attributes): array
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{}'),
    ]));
    $stack->push(Middleware::history($history));

    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', new Client(['handler' => new MockHandler]), new Client(['handler' => $stack]));
    $source = new AzDoSource(app(Vault::class));
    injectAzDoClient($source, $client);

    $event = SecurityEvent::factory()->create(array_merge([
        'source_id' => 'azdo',
        'source_event_id' => '3001',
        'state' => EventState::Open,
        'pending_state' => EventState::Dismissed,
        'pending_comment' => 'Reviewed after triage.',
    ], $attributes));

    expect($source->pushEventState($event)->ok)->toBeTrue()
        ->and($history)->toHaveCount(1);

    /** @var array<string, mixed> $body */
    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    return $body;
}

it('pushes dismissed state with expected payload shape', function () {
    $body = pushAzDoDismissal([
        'pending_comment' => 'False positive after review.',
        'metadata' => [
            'sourceProjectId' => 'project-001',
            'sourceRepoId' => 'repo-001',
            'dismissalReason' => 'falsePositive',
        ],
    ]);

    expect($body)->toBe([
        'state' => 'dismissed',
        'dismissedReason' => 'falsePositive',
        'dismissedComment' => 'False positive after review.',
    ]);
});

it('pushes the dismissal reason chosen at triage time over the metadata one', function () {
    $body = pushAzDoDismissal([
        'type' => EventType::Dependency,
        'pending_dismissal_reason' => AzDoDismissalReason::AcceptedRisk,
        'metadata' => [
            'sourceProjectId' => 'project-001',
            'sourceRepoId' => 'repo-001',
            'dismissalReason' => 'falsePositive',
        ],
    ]);

    expect($body)->toBe([
        'state' => 'dismissed',
        'dismissedReason' => 'acceptedRisk',
        'dismissedComment' => 'Reviewed after triage.',
    ]);
});

it('pushes agreedToGuidance for a license alert', function () {
    $body = pushAzDoDismissal([
        'type' => EventType::License,
        'pending_dismissal_reason' => AzDoDismissalReason::AgreedToGuidance,
        'metadata' => ['sourceProjectId' => 'project-001', 'sourceRepoId' => 'repo-001'],
    ]);

    expect($body['dismissedReason'])->toBe('agreedToGuidance');
});

it('pushes a dismissal staged without any reason as a false positive', function () {
    $body = pushAzDoDismissal([
        'metadata' => ['sourceProjectId' => 'project-001', 'sourceRepoId' => 'repo-001'],
    ]);

    expect($body['dismissedReason'])->toBe('falsePositive');
});

/**
 * Reads the upstream state of alert 3001 (project-001/repo-001) against the given advanced-security responses.
 *
 * @param  list<Response>  $responses
 * @param  list<array<string, mixed>>  $history
 */
function readAzDoUpstreamState(array $responses, array &$history): EventState
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', new Client(['handler' => new MockHandler]), new Client(['handler' => $stack]));
    $source = new AzDoSource(app(Vault::class));
    injectAzDoClient($source, $client);

    $event = SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'source_event_id' => '3001',
        'metadata' => ['sourceProjectId' => 'project-001', 'sourceRepoId' => 'repo-001'],
    ]);

    return $source->fetchUpstreamState($event);
}

function azdoSequenceError(): Response
{
    return new Response(400, [], '{"message":"Sequence contains more than one element","typeName":"System.InvalidOperationException, mscorlib"}');
}

it('reads the upstream state from the single-alert endpoint', function () {
    $history = [];

    $state = readAzDoUpstreamState([
        new Response(200, [], '{"alertId":3001,"alertType":"dependency","state":"dismissed","title":"x"}'),
    ], $history);

    expect($state)->toBe(EventState::Dismissed)
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getUri()->getPath())->toEndWith('project-001/_apis/alert/repositories/repo-001/alerts/3001');
});

it('reads the upstream state through the alert list when the single-alert endpoint answers 400', function () {
    $history = [];

    $state = readAzDoUpstreamState([
        azdoSequenceError(),
        new Response(200, [], '{"count":1,"value":[{"alertId":3001,"alertType":"dependency","state":"dismissed","title":"x"}]}'),
    ], $history);

    expect($state)->toBe(EventState::Dismissed)
        ->and($history)->toHaveCount(2)
        ->and($history[1]['request']->getUri()->getPath())->toEndWith('project-001/_apis/alert/repositories/repo-001/alerts')
        ->and(urldecode($history[1]['request']->getUri()->getQuery()))->toContain('criteria.alertIds=3001');
});

it('reports both reads when the alert list does not have the alert either', function () {
    $history = [];

    expect(fn () => readAzDoUpstreamState([
        azdoSequenceError(),
        new Response(200, [], '{"count":0,"value":[]}'),
    ], $history))->toThrow(RuntimeException::class, 'The alert list has no alert 3001 either.');
});

it('does not read the alert list for a single-alert failure other than 400', function () {
    $history = [];

    expect(function () use (&$history) {
        readAzDoUpstreamState([new Response(404, [], '{"message":"not found"}')], $history);
    })->toThrow(ClientException::class);

    expect($history)->toHaveCount(1);
});
