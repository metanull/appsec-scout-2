<?php

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Sync\PendingSyncQuery;

it('lists only dirty events grouped by source and sorted by most recent update', function () {
    $clean = SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'is_dirty' => false,
    ]);

    $azdoOlder = SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'is_dirty' => true,
        'pending_state' => EventState::Dismissed,
        'pending_comment' => 'Pending state review for the older AzDO alert.',
        'updated_at' => now()->subHours(2),
    ]);
    $azdoNewer = SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'is_dirty' => true,
        'pending_severity' => EventSeverity::Low,
        'pending_comment' => 'Pending severity review for the newer AzDO alert.',
        'updated_at' => now()->subHour(),
    ]);
    $detectify = SecurityEvent::factory()->create([
        'source_id' => 'detectify',
        'is_dirty' => true,
        'pending_state' => EventState::Resolved,
        'pending_comment' => 'Detectify change queued for upstream review.',
        'updated_at' => now()->subMinutes(30),
    ]);

    $grouped = app(PendingSyncQuery::class)->grouped();

    expect($clean->fresh()->is_dirty)->toBeFalse()
        ->and($grouped->keys()->all())->toBe(['azdo', 'detectify'])
        ->and($grouped['azdo']->pluck('event.id')->all())->toBe([$azdoNewer->id, $azdoOlder->id])
        ->and($grouped['detectify']->pluck('event.id')->all())->toBe([$detectify->id]);
});
