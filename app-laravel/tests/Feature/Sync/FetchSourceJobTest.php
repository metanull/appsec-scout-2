<?php

use App\Integrations\SystemIntegrationRuntime;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\ErrorLog;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SyncRun;
use App\Sources\Contracts\QueuesEnrichmentJobs;
use App\Sources\Contracts\Source;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;
use App\Sources\ValueObjects\PushResult;
use App\Sources\ValueObjects\SourceCapabilities;
use App\Sources\ValueObjects\TestResult;
use App\Sync\FetchSourceJob;
use App\Sync\Upserter;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeSource;

it('syncs systems containers events and preserves dirty/local metadata', function () {
    config(['integration_settings.fake.enabled' => true]);

    $source = (new FakeSource)
        ->withSystems(new SystemDto('sys-001', 'Payments API', null, null, [
            'tracker.github.repository' => 'acme/payments',
        ]))
        ->withContainers('sys-001', new ContainerDto('cont-001', 'Backend Repo', 'sys-001', 'repository'))
        ->withEvents(new EventDto(
            sourceEventId: 'evt-001',
            sourceSystemId: 'sys-001',
            sourceContainerId: 'cont-001',
            title: 'Updated title',
            severity: EventSeverity::High,
            state: EventState::Open,
            type: EventType::Vulnerability,
            metadata: ['scanner' => 'Fake Scanner'],
        ));

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    $system = SoftwareSystem::factory()->create([
        'source_id' => 'fake',
        'source_system_id' => 'sys-001',
        'name' => 'Old name',
    ]);

    $existing = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'source_event_id' => 'evt-001',
        'software_system_id' => $system->id,
        'title' => 'Old title',
        'is_dirty' => true,
        'pending_state' => EventState::Resolved,
        'pending_comment' => 'Needs sync',
        'metadata' => [
            'local' => ['note' => 'keep me'],
            'old' => 'drop me',
        ],
    ]);

    $job = new FetchSourceJob('fake');
    $job->handle(app(SystemIntegrationRuntime::class), app(Upserter::class));

    $existing->refresh();

    expect($existing->title)->toBe('Updated title')
        ->and($existing->is_dirty)->toBeTrue()
        ->and($existing->pending_state)->toBe(EventState::Resolved)
        ->and($existing->pending_comment)->toBe('Needs sync')
        ->and($existing->metadata)->toBeArray()
        ->and($existing->metadata['local']['note'])->toBe('keep me')
        ->and($existing->metadata['scanner'])->toBe('Fake Scanner');

    $run = SyncRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('success')
        ->and($run->counts_json['events_updated'])->toBe(1);
});

it('links events to containers when the event only carries the source container id', function () {
    config(['integration_settings.fake.enabled' => true]);

    $source = (new FakeSource)
        ->withSystems(new SystemDto('sys-001', 'Payments API'))
        ->withContainers('sys-001', new ContainerDto('repo-001', 'Backend Repo', 'sys-001', 'repository'))
        ->withEvents(new EventDto(
            sourceEventId: 'evt-001',
            sourceSystemId: 'sys-001',
            sourceContainerId: 'repo-001',
            title: 'Container mapped alert',
            severity: EventSeverity::High,
            state: EventState::Open,
            type: EventType::Vulnerability,
        ));

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    (new FetchSourceJob('fake'))->handle(app(SystemIntegrationRuntime::class), app(Upserter::class));

    $event = SecurityEvent::query()->where('source_event_id', 'evt-001')->first();

    expect($event)->not->toBeNull()
        ->and($event?->container_id)->not->toBeNull();
});

it('writes failure sync run when source throws', function () {
    config(['integration_settings.broken.enabled' => true]);

    $brokenSource = new class implements Source
    {
        public function id(): string
        {
            return 'broken';
        }

        public function displayName(): string
        {
            return 'Broken Source';
        }

        public function capabilities(): SourceCapabilities
        {
            return new SourceCapabilities;
        }

        public function credentialFields(): array
        {
            return [];
        }

        public function testConnection(): TestResult
        {
            return TestResult::failure('broken');
        }

        public function fetchSystems(): iterable
        {
            throw new RuntimeException('boom');
        }

        public function fetchContainers(SystemDto $system): iterable
        {
            return [];
        }

        public function fetchEvents(?Carbon $since = null, ?SystemDto $system = null): iterable
        {
            return [];
        }

        public function pushEventState(SecurityEvent $event): PushResult
        {
            return PushResult::failure('broken');
        }

        public function fetchRawEvent(SecurityEvent $event): EventDto
        {
            throw new RuntimeException('broken');
        }

        public function enrichEvent(SecurityEvent $event): ?EventDto
        {
            return null;
        }
    };

    $this->app->bind('appsec-scout.source.broken', fn () => $brokenSource);
    $this->app->tag(['appsec-scout.source.broken'], 'appsec-scout.source');

    expect(fn () => (new FetchSourceJob('broken'))->handle(app(SystemIntegrationRuntime::class), app(Upserter::class)))
        ->toThrow(RuntimeException::class, 'boom');

    $run = SyncRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('failure')
        ->and($run->error_message)->toContain('boom')
        ->and(ErrorLog::query()->where('channel', 'sync')->where('message', 'like', '%boom%')->exists())->toBeTrue();
});

it('marks the latest running sync as failed when the queued job fails outside handle', function () {
    $run = SyncRun::query()->create([
        'source_id' => 'azdo',
        'started_at' => now()->subMinutes(5),
        'status' => 'running',
        'counts_json' => [],
    ]);

    (new FetchSourceJob('azdo'))->failed(new RuntimeException('worker timeout'));

    $run->refresh();

    expect($run->status)->toBe('failure')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error_message)->toContain('worker timeout')
        ->and(ErrorLog::query()->where('channel', 'sync')->where('message', 'like', '%worker timeout%')->exists())->toBeTrue();
});

it('stores concise sync failure messages for oversized database values', function () {
    $run = SyncRun::query()->create([
        'source_id' => 'azdo',
        'started_at' => now()->subMinutes(5),
        'status' => 'running',
        'counts_json' => [],
    ]);

    (new FetchSourceJob('azdo'))->failed(new RuntimeException("SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'version_control_url' at row 1 insert into `security_events` values (...large payload...)"));

    $run->refresh();

    expect($run->error_message)->toBe('Source azdo sync failed: value too long for security_events.version_control_url. Run migrations and retry the source fetch.');
});

it('dispatches enrichment jobs returned by a source that implements QueuesEnrichmentJobs', function () {
    Queue::fake();

    $dispatchedFor = [];

    $enrichingSource = new class($dispatchedFor) extends FakeSource implements QueuesEnrichmentJobs
    {
        /** @param array<int, SecurityEvent> $dispatchedFor */
        public function __construct(private array &$dispatchedFor) {}

        public function enrichmentJobFor(string $sourceId, SecurityEvent $event): ?ShouldQueue
        {
            $this->dispatchedFor[] = $event;

            return new class implements ShouldQueue
            {
                public function handle(): void {}
            };
        }
    };

    $enrichingSource
        ->withSystems(new SystemDto('sys-001', 'Test System'))
        ->withContainers('sys-001', new ContainerDto('cont-001', 'Test Repo', 'sys-001', 'repository'))
        ->withEvents(
            new EventDto(sourceEventId: 'evt-a', sourceSystemId: 'sys-001', title: 'Alert A', severity: EventSeverity::High, state: EventState::Open, type: EventType::Vulnerability),
            new EventDto(sourceEventId: 'evt-b', sourceSystemId: 'sys-001', title: 'Alert B', severity: EventSeverity::Medium, state: EventState::Open, type: EventType::Vulnerability),
        );

    config(['integration_settings.fake.enabled' => true]);
    $this->app->bind('appsec-scout.source.fake', fn () => $enrichingSource);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    (new FetchSourceJob('fake'))->handle(app(SystemIntegrationRuntime::class), app(Upserter::class));

    // One enrichment job dispatched per event
    expect($dispatchedFor)->toHaveCount(2)
        ->and($dispatchedFor[0]->source_event_id)->toBe('evt-a')
        ->and($dispatchedFor[1]->source_event_id)->toBe('evt-b');

    Queue::assertCount(2);
});
