<?php

use App\Audit\AuditLog;
use App\Audit\Recorder;
use App\Credentials\Vault;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\ErrorLog;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SyncRun;
use App\Models\User;
use App\Sources\Registry as SourceRegistry;
use App\Sources\ValueObjects\PushResult;
use App\Sources\ValueObjects\SourceCapabilities;
use App\Sync\PendingSyncResolver;
use App\Sync\PushEventStatesJob;
use App\Triage\OperatorIntegrationRuntime;
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

    $operator = User::factory()->create();

    (new PushEventStatesJob([$event->id], $operator->id))->handle(app(OperatorIntegrationRuntime::class), app(Recorder::class), app(PendingSyncResolver::class));

    $event->refresh();

    expect($source->lastPushedState)->toBe(EventState::Resolved->value)
        ->and($event->state)->toBe(EventState::Resolved)
        ->and($event->pending_state)->toBeNull()
        ->and($event->pending_comment)->toBeNull()
        ->and($event->is_dirty)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'sync_push')->where('subject_id', (string) $event->id)->exists())->toBeTrue()
        ->and(SyncRun::query()->latest('id')->first()?->status)->toBe('success');
});

it('uses the operator credential when pushing selected dirty records from the queue', function () {
    $operator = User::factory()->create();
    app(Vault::class)->set('fake.apiKey', null, 'system-key');
    app(Vault::class)->set('fake.apiKey', $operator->id, 'operator-key');

    $source = bindFakePushSource((new FakeSource)->withPushCallback(function (): PushResult {
        return app(Vault::class)->get('fake.apiKey', null, true) === 'operator-key'
            ? PushResult::success()
            : PushResult::failure('operator credential was not used');
    }));

    $system = SoftwareSystem::factory()->create([
        'source_id' => 'fake',
        'source_system_id' => 'sys-001',
    ]);
    $event = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'software_system_id' => $system->id,
        'state' => EventState::Open,
        'pending_state' => EventState::Resolved,
        'pending_severity' => null,
        'pending_comment' => 'Operator scoped push.',
        'is_dirty' => true,
    ]);

    (new PushEventStatesJob([$event->id], $operator->id))->handle(app(OperatorIntegrationRuntime::class), app(Recorder::class), app(PendingSyncResolver::class));

    $event->refresh();

    expect($source->pushCalls)->toBe(1)
        ->and($event->is_dirty)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'sync_push')->first()?->payload_json['operator_user_id'])->toBe($operator->id);
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

    $operator = User::factory()->create();

    (new PushEventStatesJob([$event->id], $operator->id))->handle(app(OperatorIntegrationRuntime::class), app(Recorder::class), app(PendingSyncResolver::class));

    $event->refresh();

    expect($event->is_dirty)->toBeTrue()
        ->and($event->pending_state)->toBe(EventState::Dismissed)
        ->and($event->metadata['pushRetryCount'])->toBe(1)
        ->and($event->metadata['lastPushError'])->toBe('upstream error')
        ->and(ErrorLog::query()->where('channel', 'sync')->exists())->toBeTrue()
        ->and(SyncRun::query()->latest('id')->first()?->status)->toBe('failure');
});

it('preserves pending severity as a local-only annotation after a successful state push', function () {
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

    $operator = User::factory()->create();

    (new PushEventStatesJob([$event->id], $operator->id))->handle(app(OperatorIntegrationRuntime::class), app(Recorder::class), app(PendingSyncResolver::class));

    $event->refresh();

    // FakeSource declares canUpdateSeverity: false, matching every real Source today — the
    // severity change can never be pushed, so it no longer keeps the event flagged dirty forever.
    // The staged value itself is preserved as a durable local annotation.
    expect($event->state)->toBe(EventState::Resolved)
        ->and($event->pending_state)->toBeNull()
        ->and($event->pending_severity)->toBe(EventSeverity::Low)
        ->and($event->is_dirty)->toBeFalse()
        ->and($event->comments()->latest('id')->first()?->body)->toContain('Severity change to "low" could not be pushed')
        ->and(ErrorLog::query()->where('channel', 'sync')->where('level', 'warning')->exists())->toBeTrue();
});

it('resolves a severity-only change as local-only without ever attempting a push', function () {
    $source = bindFakePushSource(new FakeSource);
    $system = SoftwareSystem::factory()->create([
        'source_id' => 'fake',
        'source_system_id' => 'sys-001',
    ]);
    $event = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'software_system_id' => $system->id,
        'pending_state' => null,
        'pending_severity' => EventSeverity::Critical,
        'is_dirty' => true,
    ]);

    $operator = User::factory()->create();

    (new PushEventStatesJob([$event->id], $operator->id))->handle(app(OperatorIntegrationRuntime::class), app(Recorder::class), app(PendingSyncResolver::class));

    $event->refresh();

    expect($source->pushCalls)->toBe(0)
        ->and($event->pending_severity)->toBe(EventSeverity::Critical)
        ->and($event->is_dirty)->toBeFalse()
        ->and($event->comments()->latest('id')->first()?->body)->toContain('Severity change to "critical" could not be pushed')
        ->and(SyncRun::query()->latest('id')->first()?->counts_json['events_resolved_local_only'])->toBe(1);
});

it('resolves a standalone-comment-only dirty event as local-only', function () {
    $source = bindFakePushSource(new FakeSource);
    $system = SoftwareSystem::factory()->create([
        'source_id' => 'fake',
        'source_system_id' => 'sys-001',
    ]);
    $event = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'software_system_id' => $system->id,
        'pending_state' => null,
        'pending_severity' => null,
        'is_dirty' => true,
    ]);

    $operator = User::factory()->create();

    (new PushEventStatesJob([$event->id], $operator->id))->handle(app(OperatorIntegrationRuntime::class), app(Recorder::class), app(PendingSyncResolver::class));

    $event->refresh();

    expect($source->pushCalls)->toBe(0)
        ->and($event->is_dirty)->toBeFalse()
        ->and($event->comments()->latest('id')->first()?->body)->toContain('This comment could not be pushed');
});

it('leaves a misconfigured canPushStandaloneComment source dirty and logs an error instead of silently resolving', function () {
    $source = bindFakePushSource(new class extends FakeSource
    {
        public function capabilities(): SourceCapabilities
        {
            return new SourceCapabilities(
                canUpdateState: true,
                canPushStandaloneComment: true,
            );
        }
    });
    $system = SoftwareSystem::factory()->create([
        'source_id' => 'fake',
        'source_system_id' => 'sys-001',
    ]);
    $event = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'software_system_id' => $system->id,
        'pending_state' => null,
        'pending_severity' => null,
        'is_dirty' => true,
    ]);

    $operator = User::factory()->create();

    (new PushEventStatesJob([$event->id], $operator->id))->handle(app(OperatorIntegrationRuntime::class), app(Recorder::class), app(PendingSyncResolver::class));

    $event->refresh();

    expect($source->pushCalls)->toBe(0)
        ->and($event->is_dirty)->toBeTrue()
        ->and(ErrorLog::query()->where('channel', 'sync')->where('level', 'error')->where('message', 'like', '%canPushStandaloneComment%')->exists())->toBeTrue();
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

    $operator = User::factory()->create();

    (new PushEventStatesJob([$event->id], $operator->id))->handle(app(OperatorIntegrationRuntime::class), app(Recorder::class), app(PendingSyncResolver::class));

    $event->refresh();

    expect($source->pushCalls)->toBe(0)
        ->and($event->metadata['pushRetryCount'])->toBe(3)
        ->and($event->is_dirty)->toBeTrue()
        ->and(SyncRun::query()->latest('id')->first()?->counts_json['events_skipped'])->toBe(1);
});

function bindFakePushSource(FakeSource $source): FakeSource
{
    app()->bind('appsec-scout.source.fake', fn () => $source);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');
    app()->forgetInstance(SourceRegistry::class);

    return $source;
}
