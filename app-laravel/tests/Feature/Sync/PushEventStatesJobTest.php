<?php

use App\Audit\AuditLog;
use App\Audit\Recorder;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\ErrorLog;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SyncRun;
use App\Sources\Registry;
use App\Sync\PushEventStatesJob;
use Tests\Fakes\FakeSource;

it('pushes pending state successfully and clears the dirty state', function () {
    $source = bindFakePushSource(new FakeSource);
    $system = SoftwareSystem::factory()->create([
        'source_id' => 'fake',
        'source_system_id' => 'sys-001',
    ]);
    $event = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'software_system_id' => $system->id,
        'state' => EventState::Open,
        'pending_state' => EventState::Resolved,
        'pending_comment' => 'Patched and verified for the next upstream push.',
        'is_dirty' => true,
    ]);

    (new PushEventStatesJob([$event->id]))->handle(app(Registry::class), app(Recorder::class));

    $event->refresh();

    expect($source->lastPushedState)->toBe(EventState::Resolved->value)
        ->and($event->state)->toBe(EventState::Resolved)
        ->and($event->pending_state)->toBeNull()
        ->and($event->pending_comment)->toBeNull()
        ->and($event->is_dirty)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'sync_push')->where('subject_id', (string) $event->id)->exists())->toBeTrue()
        ->and(SyncRun::query()->latest('id')->first()?->status)->toBe('success');
});

it('preserves the dirty state and records retry metadata when a push fails', function () {
    bindFakePushSource((new FakeSource)->withPushFailure());
    $system = SoftwareSystem::factory()->create([
        'source_id' => 'fake',
        'source_system_id' => 'sys-001',
    ]);
    $event = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'software_system_id' => $system->id,
        'pending_state' => EventState::Dismissed,
        'pending_comment' => 'False positive after manual validation.',
        'is_dirty' => true,
        'metadata' => [],
    ]);

    (new PushEventStatesJob([$event->id]))->handle(app(Registry::class), app(Recorder::class));

    $event->refresh();

    expect($event->is_dirty)->toBeTrue()
        ->and($event->pending_state)->toBe(EventState::Dismissed)
        ->and($event->metadata['pushRetryCount'])->toBe(1)
        ->and($event->metadata['lastPushError'])->toBe('upstream error')
        ->and(ErrorLog::query()->where('channel', 'sync')->exists())->toBeTrue()
        ->and(SyncRun::query()->latest('id')->first()?->status)->toBe('failure');
});

it('preserves pending severity data after a successful state push', function () {
    bindFakePushSource(new FakeSource);
    $system = SoftwareSystem::factory()->create([
        'source_id' => 'fake',
        'source_system_id' => 'sys-001',
    ]);
    $event = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'software_system_id' => $system->id,
        'state' => EventState::Open,
        'pending_state' => EventState::Resolved,
        'pending_severity' => EventSeverity::Low,
        'pending_comment' => 'State accepted, severity review remains local for now.',
        'is_dirty' => true,
    ]);

    (new PushEventStatesJob([$event->id]))->handle(app(Registry::class), app(Recorder::class));

    $event->refresh();

    expect($event->state)->toBe(EventState::Resolved)
        ->and($event->pending_state)->toBeNull()
        ->and($event->pending_severity)->toBe(EventSeverity::Low)
        ->and($event->is_dirty)->toBeTrue();
});

it('stops retrying automatically after the third failure', function () {
    $source = bindFakePushSource((new FakeSource)->withPushFailure());
    $system = SoftwareSystem::factory()->create([
        'source_id' => 'fake',
        'source_system_id' => 'sys-001',
    ]);
    $event = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'software_system_id' => $system->id,
        'pending_state' => EventState::Dismissed,
        'pending_comment' => 'Already failed three times and should not retry automatically.',
        'is_dirty' => true,
        'metadata' => [
            'pushRetryCount' => 3,
            'lastPushError' => 'upstream error',
        ],
    ]);

    (new PushEventStatesJob([$event->id]))->handle(app(Registry::class), app(Recorder::class));

    $event->refresh();

    expect($source->pushCalls)->toBe(0)
        ->and($event->metadata['pushRetryCount'])->toBe(3)
        ->and($event->is_dirty)->toBeTrue()
        ->and(SyncRun::query()->latest('id')->first()?->counts_json['events_skipped'])->toBe(1);
});

function bindFakePushSource(FakeSource $source): FakeSource
{
    config(['integration_settings.fake.enabled' => true]);

    app()->bind('appsec-scout.source.fake', fn () => $source);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    return $source;
}
