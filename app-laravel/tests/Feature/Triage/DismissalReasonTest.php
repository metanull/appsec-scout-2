<?php

use App\Audit\AuditLog;
use App\Filament\Resources\PendingSyncResource\Pages\ListPendingSync;
use App\Filament\Resources\SecurityEventResource\Pages\ListSecurityEvents;
use App\Filament\Resources\SecurityEventResource\Pages\ViewSecurityEvent;
use App\Models\Enums\AzDoDismissalReason;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sync\PendingChangeDiscarder;
use App\Triage\StateChanger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function dismissalTriager(): User
{
    $user = User::factory()->create();
    $user->syncRoles(['Triage']);

    return $user;
}

it('stores the chosen reason with a pending AzDO dismissal and audits it', function () {
    $user = dismissalTriager();
    $event = SecurityEvent::factory()->dependency()->create(['source_id' => 'azdo', 'state' => EventState::Open]);

    $updated = app(StateChanger::class)->change($event, $user, EventState::Dismissed, 'Build-only tooling, never shipped.', AzDoDismissalReason::AcceptedRisk);
    $audit = AuditLog::query()->where('action', 'state_change')->where('subject_id', (string) $event->id)->sole();

    expect($updated->pending_state)->toBe(EventState::Dismissed)
        ->and($updated->pending_dismissal_reason)->toBe(AzDoDismissalReason::AcceptedRisk)
        ->and($updated->comments()->latest('id')->first()?->body)->toBe('[State change: dismissed (Accepted risk)] Build-only tooling, never shipped.')
        ->and($audit->payload_json['dismissal_reason'])->toBe('acceptedRisk');
});

it('requires a reason to dismiss an AzDO alert', function () {
    $event = SecurityEvent::factory()->create(['source_id' => 'azdo']);

    expect(fn () => app(StateChanger::class)->change($event, dismissalTriager(), EventState::Dismissed, 'Dismissed without saying why.'))
        ->toThrow(ValidationException::class, 'requires a dismissal reason');

    expect($event->fresh()?->is_dirty)->toBeFalse();
});

it('rejects a reason that does not apply to the alert type', function () {
    $event = SecurityEvent::factory()->secret()->create(['source_id' => 'azdo']);

    expect(fn () => app(StateChanger::class)->change($event, dismissalTriager(), EventState::Dismissed, 'Secret is not shipped anywhere.', AzDoDismissalReason::NotDistributed))
        ->toThrow(ValidationException::class, 'does not apply to secret alerts');
});

it('accepts agreedToGuidance for license alerts only', function () {
    $user = dismissalTriager();
    $license = SecurityEvent::factory()->create(['source_id' => 'azdo', 'type' => EventType::License]);
    $dependency = SecurityEvent::factory()->dependency()->create(['source_id' => 'azdo']);

    app(StateChanger::class)->change($license, $user, EventState::Dismissed, 'License terms reviewed with legal.', AzDoDismissalReason::AgreedToGuidance);

    expect($license->fresh()?->pending_dismissal_reason)->toBe(AzDoDismissalReason::AgreedToGuidance)
        ->and(fn () => app(StateChanger::class)->change($dependency, $user, EventState::Dismissed, 'License terms reviewed with legal.', AzDoDismissalReason::AgreedToGuidance))
        ->toThrow(ValidationException::class, 'does not apply to dependency alerts');
});

it('offers each type-specific reason only for its own alert type', function (EventType $type, array $expected) {
    expect(array_map(fn (AzDoDismissalReason $reason): string => $reason->value, AzDoDismissalReason::applicableToAll([$type])))
        ->toBe($expected);
})->with([
    'dependency' => [EventType::Dependency, ['acceptedRisk', 'falsePositive', 'notDistributed']],
    'license' => [EventType::License, ['acceptedRisk', 'falsePositive', 'agreedToGuidance']],
    'secret' => [EventType::Secret, ['acceptedRisk', 'falsePositive']],
    'code' => [EventType::Vulnerability, ['acceptedRisk', 'falsePositive']],
]);

it('rejects a reason on anything but a dismissal', function () {
    $event = SecurityEvent::factory()->create(['source_id' => 'azdo']);

    expect(fn () => app(StateChanger::class)->change($event, dismissalTriager(), EventState::Resolved, 'Fixed in the latest release.', AzDoDismissalReason::FalsePositive))
        ->toThrow(ValidationException::class, 'only applies when the new state is dismissed');
});

it('dismisses alerts of other sources without a reason and stores none for them', function () {
    $user = dismissalTriager();
    $azdo = SecurityEvent::factory()->dependency()->create(['source_id' => 'azdo']);
    $asoc = SecurityEvent::factory()->create(['source_id' => 'asoc']);

    app(StateChanger::class)->changeMany([$azdo, $asoc], $user, EventState::Dismissed, 'Test-only dependency across the board.', AzDoDismissalReason::NotDistributed);

    expect($azdo->fresh()?->pending_dismissal_reason)->toBe(AzDoDismissalReason::NotDistributed)
        ->and($asoc->fresh()?->pending_dismissal_reason)->toBeNull()
        ->and($asoc->fresh()?->pending_state)->toBe(EventState::Dismissed);
});

it('clears the reason when the alert is re-triaged to another state', function () {
    $user = dismissalTriager();
    $event = SecurityEvent::factory()->create(['source_id' => 'azdo', 'type' => EventType::Vulnerability]);

    app(StateChanger::class)->change($event, $user, EventState::Dismissed, 'Not exploitable in this context.', AzDoDismissalReason::FalsePositive);
    app(StateChanger::class)->change($event->fresh() ?? $event, $user, EventState::InProgress, 'Reopened: needs a closer look.');

    expect($event->fresh()?->pending_dismissal_reason)->toBeNull();
});

it('clears the reason when the pending change is discarded', function () {
    $event = SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'is_dirty' => true,
        'pending_state' => EventState::Dismissed,
        'pending_dismissal_reason' => AzDoDismissalReason::AcceptedRisk,
        'pending_comment' => 'Build-only tooling, never shipped.',
    ]);

    app(PendingChangeDiscarder::class)->discard([$event], dismissalTriager());
    $audit = AuditLog::query()->where('action', 'pending_change_discarded')->sole();

    expect($event->fresh()?->pending_dismissal_reason)->toBeNull()
        ->and($audit->payload_json['discarded']['pending_dismissal_reason'])->toBe('acceptedRisk');
});

it('shows and requires the reason in the row action only for an AzDO dismissal', function () {
    $user = dismissalTriager();
    $azdo = SecurityEvent::factory()->dependency()->create(['source_id' => 'azdo']);
    $asoc = SecurityEvent::factory()->create(['source_id' => 'asoc']);

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->mountTableAction('changeState', $azdo)
        ->fillForm(['new_state' => EventState::Resolved->value])
        ->assertFormFieldHidden('dismissal_reason')
        ->fillForm(['new_state' => EventState::Dismissed->value])
        ->assertFormFieldVisible('dismissal_reason')
        ->fillForm(['comment' => 'Build-only tooling, never shipped.'])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['dismissal_reason' => 'required']);

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->mountTableAction('changeState', $asoc)
        ->fillForm(['new_state' => EventState::Dismissed->value])
        ->assertFormFieldDoesNotExist('dismissal_reason');

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->callTableAction('changeState', $azdo, data: [
            'new_state' => EventState::Dismissed->value,
            'dismissal_reason' => AzDoDismissalReason::NotDistributed->value,
            'comment' => 'Build-only tooling, never shipped.',
        ])
        ->assertHasNoTableActionErrors();

    expect($azdo->fresh()?->pending_dismissal_reason)->toBe(AzDoDismissalReason::NotDistributed);
});

it('offers only the reasons valid for every selected AzDO alert in the bulk action', function () {
    $user = dismissalTriager();
    $dependency = SecurityEvent::factory()->dependency()->create(['source_id' => 'azdo']);
    $secret = SecurityEvent::factory()->secret()->create(['source_id' => 'azdo']);

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->callTableBulkAction('changeStateBulk', [$dependency, $secret], data: [
            'new_state' => EventState::Dismissed->value,
            'dismissal_reason' => AzDoDismissalReason::NotDistributed->value,
            'comment' => 'Mixed selection of alerts.',
        ])
        ->assertHasTableBulkActionErrors(['dismissal_reason']);

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->callTableBulkAction('changeStateBulk', [$dependency, $secret], data: [
            'new_state' => EventState::Dismissed->value,
            'dismissal_reason' => AzDoDismissalReason::AcceptedRisk->value,
            'comment' => 'Mixed selection of alerts.',
        ])
        ->assertHasNoTableBulkActionErrors();

    expect($dependency->fresh()?->pending_dismissal_reason)->toBe(AzDoDismissalReason::AcceptedRisk)
        ->and($secret->fresh()?->pending_dismissal_reason)->toBe(AzDoDismissalReason::AcceptedRisk);
});

it('stores the reason from the alert view page', function () {
    $event = SecurityEvent::factory()->dependency()->create(['source_id' => 'azdo']);

    Livewire::actingAs(dismissalTriager())
        ->test(ViewSecurityEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('changeState', data: [
            'new_state' => EventState::Dismissed->value,
            'dismissal_reason' => AzDoDismissalReason::AcceptedRisk->value,
            'comment' => 'Build-only tooling, never shipped.',
        ])
        ->assertHasNoActionErrors();

    expect($event->fresh()?->pending_dismissal_reason)->toBe(AzDoDismissalReason::AcceptedRisk);
});

it('shows the pending reason on the Pending Sync page', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Sync']);
    SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'is_dirty' => true,
        'pending_state' => EventState::Dismissed,
        'pending_dismissal_reason' => AzDoDismissalReason::AcceptedRisk,
        'pending_comment' => 'Build-only tooling, never shipped.',
    ]);

    Livewire::actingAs($user)
        ->test(ListPendingSync::class)
        ->assertSee('Accepted risk');
});
