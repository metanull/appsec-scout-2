<?php

use App\Audit\AuditLog;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Triage\StateChanger;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('applies the same pending state and comment to five selected events', function () {
    $user = bulkTriageUser();
    $events = SecurityEvent::factory()->count(5)->create([
        'state' => EventState::Open,
        'is_dirty' => false,
        'pending_state' => null,
        'pending_comment' => null,
    ]);

    $this->actingAs($user);

    $updated = app(StateChanger::class)->changeMany(
        $events,
        $user,
        EventState::Dismissed,
        'Bulk false-positive review completed for this shared rule signature.',
    );

    expect($updated)->toHaveCount(5)
        ->and(SecurityEvent::query()->where('pending_state', EventState::Dismissed)->count())->toBe(5)
        ->and(SecurityEvent::query()->where('pending_comment', 'Bulk false-positive review completed for this shared rule signature.')->count())->toBe(5)
        ->and(SecurityEvent::query()->where('is_dirty', true)->count())->toBe(5);

    $audit = AuditLog::query()->where('action', 'bulk_state_change')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->payload_json['count'])->toBe(5)
        ->and($audit?->payload_json['new_state'])->toBe(EventState::Dismissed->value)
        ->and($audit?->payload_json['event_ids'])->toBe($events->pluck('id')->all());
});

function bulkTriageUser(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Triage']);

    return $user;
}
