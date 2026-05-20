<?php

use App\Audit\AuditLog;
use App\Filament\Resources\SecurityEventResource;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Triage\StateChanger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('changes alert state locally and records the pending review data', function () {
    $user = triageUser();
    $event = SecurityEvent::factory()->create([
        'state' => EventState::Open,
        'is_dirty' => false,
        'pending_state' => null,
        'pending_comment' => null,
    ]);

    $this->actingAs($user);

    $updated = app(StateChanger::class)->change($event, $user, EventState::Dismissed, 'False positive after validating the detector output.');

    expect($updated->state)->toBe(EventState::Open)
        ->and($updated->pending_state)->toBe(EventState::Dismissed)
        ->and($updated->pending_comment)->toBe('False positive after validating the detector output.')
        ->and($updated->is_dirty)->toBeTrue()
        ->and($updated->comments()->latest('id')->first()?->body)->toBe('[State change: dismissed] False positive after validating the detector output.')
        ->and(AuditLog::query()->where('action', 'state_change')->where('subject_id', (string) $event->id)->exists())->toBeTrue();
});

it('requires a justification comment of at least ten characters', function () {
    $user = triageUser();
    $event = SecurityEvent::factory()->create();

    expect(fn () => app(StateChanger::class)->change($event, $user, EventState::Resolved, 'Too short'))
        ->toThrow(ValidationException::class);
});

it('hides the change state action from a reader', function () {
    $user = readerUser();
    $event = SecurityEvent::factory()->create();

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertDontSee('Change state');
});

function triageUser(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Triage']);

    return $user;
}

function readerUser(): User
{
    return User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
}
