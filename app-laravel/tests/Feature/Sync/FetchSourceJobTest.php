<?php

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SyncRun;
use App\Sources\Contracts\Source;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;
use App\Sources\Registry;
use App\Sources\ValueObjects\PushResult;
use App\Sources\ValueObjects\SourceCapabilities;
use App\Sources\ValueObjects\TestResult;
use App\Sync\FetchSourceJob;
use App\Sync\Upserter;
use Carbon\Carbon;
use Tests\Fakes\FakeSource;

it('syncs systems containers events and preserves dirty/local metadata', function () {
    config(['integration_settings.fake.enabled' => true]);

    $source = (new FakeSource)
        ->withSystems(new SystemDto('sys-001', 'Payments API'))
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
    $job->handle(app(Registry::class), app(Upserter::class));

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

        public function requiredCredentialKeys(): array
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

    expect(fn () => (new FetchSourceJob('broken'))->handle(app(Registry::class), app(Upserter::class)))
        ->toThrow(RuntimeException::class, 'boom');

    $run = SyncRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('failure')
        ->and($run->error_message)->toContain('boom');
});
