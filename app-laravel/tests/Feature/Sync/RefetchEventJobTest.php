<?php

use App\Audit\AuditLog;
use App\Audit\Recorder;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Sources\Dto\EventDto;
use App\Sources\Registry;
use App\Sync\RefetchEventJob;
use App\Sync\Upserter;
use Tests\Fakes\FakeSource;

it('refreshes a single event from its source through the upserter path', function () {
    $system = SoftwareSystem::factory()->create([
        'source_id' => 'fake',
        'source_system_id' => 'sys-001',
    ]);
    $container = SecurityContainer::factory()->forSystem($system)->create([
        'source_container_id' => 'cont-001',
    ]);
    bindFakeRefetchSource((new FakeSource)->withRawEvent(new EventDto(
        sourceEventId: 'evt-001',
        sourceSystemId: 'sys-001',
        sourceContainerId: 'cont-001',
        title: 'Updated upstream title',
        severity: EventSeverity::Critical,
        state: EventState::Resolved,
        type: EventType::Vulnerability,
    )));

    $event = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'source_event_id' => 'evt-001',
        'software_system_id' => $system->id,
        'container_id' => $container->id,
        'title' => 'Original title',
        'severity' => EventSeverity::High,
        'state' => EventState::Open,
    ]);

    (new RefetchEventJob($event->id))->handle(app(Registry::class), app(Upserter::class), app(Recorder::class));

    $event->refresh();

    expect($event->title)->toBe('Updated upstream title')
        ->and($event->severity)->toBe(EventSeverity::Critical)
        ->and($event->state)->toBe(EventState::Resolved)
        ->and(AuditLog::query()->where('action', 'event_refetched')->where('subject_id', (string) $event->id)->exists())->toBeTrue();
});

it('preserves dirty pending state and pending severity across a refresh', function () {
    $system = SoftwareSystem::factory()->create([
        'source_id' => 'fake',
        'source_system_id' => 'sys-001',
    ]);
    bindFakeRefetchSource((new FakeSource)->withRawEvent(new EventDto(
        sourceEventId: 'evt-002',
        sourceSystemId: 'sys-001',
        title: 'Refetched title',
        severity: EventSeverity::Informational,
        state: EventState::Acknowledged,
        type: EventType::Vulnerability,
    )));

    $event = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'source_event_id' => 'evt-002',
        'software_system_id' => $system->id,
        'title' => 'Local pending title',
        'severity' => EventSeverity::High,
        'state' => EventState::Open,
        'is_dirty' => true,
        'pending_state' => EventState::Dismissed,
        'pending_severity' => EventSeverity::Low,
        'pending_comment' => 'Keep the local triage decisions while refreshing upstream fields.',
    ]);

    (new RefetchEventJob($event->id))->handle(app(Registry::class), app(Upserter::class), app(Recorder::class));

    $event->refresh();

    expect($event->title)->toBe('Refetched title')
        ->and($event->state)->toBe(EventState::Acknowledged)
        ->and($event->severity)->toBe(EventSeverity::Informational)
        ->and($event->is_dirty)->toBeTrue()
        ->and($event->pending_state)->toBe(EventState::Dismissed)
        ->and($event->pending_severity)->toBe(EventSeverity::Low)
        ->and($event->pending_comment)->toBe('Keep the local triage decisions while refreshing upstream fields.');
});

function bindFakeRefetchSource(FakeSource $source): FakeSource
{
    config(['integration_settings.fake.enabled' => true]);

    app()->bind('appsec-scout.source.fake', fn () => $source);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    return $source;
}
