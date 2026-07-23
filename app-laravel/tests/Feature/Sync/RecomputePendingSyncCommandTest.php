<?php

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\ErrorLog;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Sources\Registry as SourceRegistry;
use Tests\Fakes\FakeSource;

it('resolves stuck severity-only and comment-only dirty events as local-only', function () {
    bindRecomputeFakeSource(new FakeSource);
    $system = SoftwareSystem::factory()->create(['source_id' => 'fake', 'source_system_id' => 'sys-001']);

    $severityOnly = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'software_system_id' => $system->id,
        'pending_state' => null,
        'pending_severity' => EventSeverity::High,
        'is_dirty' => true,
    ]);
    $commentOnly = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'software_system_id' => $system->id,
        'pending_state' => null,
        'pending_severity' => null,
        'is_dirty' => true,
    ]);
    $stillPushable = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'software_system_id' => $system->id,
        'pending_state' => EventState::Resolved,
        'is_dirty' => true,
    ]);

    $this->artisan('events:recompute-pending-sync')
        ->expectsOutputToContain('Resolved 2 event(s)')
        ->assertSuccessful();

    expect($severityOnly->fresh()->is_dirty)->toBeFalse()
        ->and($severityOnly->fresh()->pending_severity)->toBe(EventSeverity::High)
        ->and($commentOnly->fresh()->is_dirty)->toBeFalse()
        ->and($stillPushable->fresh()->is_dirty)->toBeTrue()
        ->and(ErrorLog::query()->where('channel', 'sync')->where('level', 'warning')->count())->toBe(2);
});

it('is safe to re-run: already-resolved events are skipped', function () {
    bindRecomputeFakeSource(new FakeSource);
    $system = SoftwareSystem::factory()->create(['source_id' => 'fake', 'source_system_id' => 'sys-001']);

    SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'software_system_id' => $system->id,
        'pending_state' => null,
        'pending_severity' => EventSeverity::Medium,
        'is_dirty' => true,
    ]);

    $this->artisan('events:recompute-pending-sync')->assertSuccessful();
    $this->artisan('events:recompute-pending-sync')
        ->expectsOutputToContain('Resolved 0 event(s)')
        ->assertSuccessful();
});

function bindRecomputeFakeSource(FakeSource $source): FakeSource
{
    app()->bind('appsec-scout.source.fake', fn () => $source);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');
    app()->forgetInstance(SourceRegistry::class);

    return $source;
}
