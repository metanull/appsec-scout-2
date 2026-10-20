<?php

use App\Assets\AttachmentTargetResolver;
use App\Assets\StaleRecordSweeper;
use App\Credentials\Vault;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Sources\Asoc\AsocClient;
use App\Sources\Asoc\AsocSource;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;
use App\Sync\FetchSourceJob;
use App\Sync\SystemIntegrationRuntime;
use App\Sync\Upserter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\Fakes\FakeSource;

/** @return array{source: SoftwareSystem, target: SoftwareSystem, container: SecurityContainer} */
function relinkedFixture(): array
{
    $source = SoftwareSystem::factory()->create(['source_id' => 'fake', 'source_system_id' => 'sys-1', 'name' => 'Source system']);
    $target = SoftwareSystem::factory()->create(['source_id' => 'fake', 'source_system_id' => 'sys-2', 'name' => 'Target system']);
    $container = SecurityContainer::factory()->relinked($source, $target)->create(['source_container_id' => 'repo-1', 'name' => 'Old name']);

    return ['source' => $source, 'target' => $target, 'container' => $container];
}

function fetchFake(FakeSource $source): void
{
    app()->bind('appsec-scout.source.fake', fn () => $source);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    (new FetchSourceJob('fake'))->handle(app(SystemIntegrationRuntime::class), app(Upserter::class));
}

function overrideEvent(string $id): EventDto
{
    return new EventDto(
        sourceEventId: $id,
        sourceSystemId: 'sys-1',
        sourceContainerId: 'repo-1',
        title: "Alert {$id}",
        severity: EventSeverity::High,
        state: EventState::Open,
        type: EventType::Vulnerability,
    );
}

it('defaults a new container\'s source system to the system it is linked to', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::query()->create(['software_system_id' => $system->id, 'source_container_id' => 'repo-9', 'name' => 'repo-9']);

    expect($container->fresh()?->source_software_system_id)->toBe($system->id)
        ->and($container->isSystemOverridden())->toBeFalse();
});

it('keeps a re-linked container on its chosen system across a fetch, without duplicating it', function () {
    ['source' => $source, 'target' => $target, 'container' => $container] = relinkedFixture();

    fetchFake((new FakeSource)
        ->withSystems(new SystemDto('sys-1', 'Source system'), new SystemDto('sys-2', 'Target system'))
        ->withContainers('sys-1', new ContainerDto('repo-1', 'New name', 'sys-1', 'repository'))
        ->withEvents(overrideEvent('evt-1')));

    $container->refresh();
    $event = SecurityEvent::query()->where('source_event_id', 'evt-1')->sole();

    expect(SecurityContainer::query()->where('source_container_id', 'repo-1')->count())->toBe(1)
        ->and($container->name)->toBe('New name')
        ->and($container->software_system_id)->toBe($target->id)
        ->and($container->source_software_system_id)->toBe($source->id)
        ->and($container->removed_at)->toBeNull()
        ->and($event->container_id)->toBe($container->id)
        ->and($event->software_system_id)->toBe($target->id);
});

it('still links a container that was never re-linked to its source system', function () {
    $source = SoftwareSystem::factory()->create(['source_id' => 'fake', 'source_system_id' => 'sys-1']);
    $container = SecurityContainer::factory()->forSystem($source)->create(['source_container_id' => 'repo-1']);

    fetchFake((new FakeSource)
        ->withSystems(new SystemDto('sys-1', 'Source system'))
        ->withContainers('sys-1', new ContainerDto('repo-1', 'repo-1', 'sys-1', 'repository'))
        ->withEvents(overrideEvent('evt-1')));

    expect($container->fresh()?->software_system_id)->toBe($source->id)
        ->and(SecurityEvent::query()->where('source_event_id', 'evt-1')->value('software_system_id'))->toBe($source->id);
});

it('sweeps and restores a re-linked container by its source system', function () {
    ['source' => $source, 'container' => $container] = relinkedFixture();
    $sweeper = app(StaleRecordSweeper::class);

    $sweeper->sweepContainers('fake', []);
    expect($container->fresh()?->removed_at)->not->toBeNull();

    $sweeper->sweepContainers('fake', [$container->id]);
    expect($container->fresh()?->removed_at)->toBeNull();
});

it('attaches collected scan results to the re-linked container itself', function () {
    ['source' => $source, 'container' => $container] = relinkedFixture();

    $resolved = app(AttachmentTargetResolver::class)->resolveContainer($source, 'repo-1', 'repo-1', 'repository');

    expect($resolved->id)->toBe($container->id)
        ->and(SecurityContainer::query()->where('source_container_id', 'repo-1')->count())->toBe(1);
});

it('addresses writeback to the source-reported system of a re-linked alert', function () {
    $asocSource = SoftwareSystem::factory()->create(['source_id' => 'asoc', 'source_system_id' => 'app-original']);
    $asocTarget = SoftwareSystem::factory()->create(['source_id' => 'asoc', 'source_system_id' => 'app-other']);
    $container = SecurityContainer::factory()->relinked($asocSource, $asocTarget)->create();
    $event = SecurityEvent::factory()->forContainer($container)->create([
        'source_id' => 'asoc',
        'source_event_id' => '1005',
        'pending_state' => EventState::Resolved,
        'pending_comment' => 'Fixed upstream.',
    ]);

    expect($event->software_system_id)->toBe($asocTarget->id)
        ->and($event->upstreamSoftwareSystem()?->id)->toBe($asocSource->id);

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"Token":"token-1"}'),
        new Response(200, [], '{"NProvidedIssues":1,"NUpdatedIssues":1}'),
    ]));
    $stack->push(Middleware::history($history));

    $source = new AsocSource(app(Vault::class));
    $property = (new ReflectionClass($source))->getProperty('client');
    $property->setAccessible(true);
    $property->setValue($source, new AsocClient('key-id', 'key-secret', 'https://cloud.appscan.com', new Client(['handler' => $stack])));

    expect($source->pushEventState($event->fresh(['softwareSystem', 'container.sourceSoftwareSystem']))->ok)->toBeTrue()
        ->and((string) $history[1]['request']->getUri())->toContain('app-original')
        ->and((string) $history[1]['request']->getUri())->not->toContain('app-other');
});
