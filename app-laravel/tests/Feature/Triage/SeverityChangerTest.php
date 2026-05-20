<?php

use App\Audit\AuditLog;
use App\Filament\Resources\SecurityEventResource;
use App\Models\Enums\EventSeverity;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Triage\SeverityChanger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('queues a local severity change without changing the synced severity', function () {
    $user = severityTriageUser();
    $event = SecurityEvent::factory()->create([
        'severity' => EventSeverity::High,
        'pending_severity' => null,
        'pending_comment' => null,
        'is_dirty' => false,
    ]);

    $this->actingAs($user);

    $updated = app(SeverityChanger::class)->change($event, $user, EventSeverity::Low, 'Reducing severity after validating the actual exploitability.');

    expect($updated->severity)->toBe(EventSeverity::High)
        ->and($updated->pending_severity)->toBe(EventSeverity::Low)
        ->and($updated->pending_comment)->toBe('Reducing severity after validating the actual exploitability.')
        ->and($updated->is_dirty)->toBeTrue()
        ->and($updated->comments()->latest('id')->first()?->body)->toBe('[Severity change: low] Reducing severity after validating the actual exploitability.')
        ->and(AuditLog::query()->where('action', 'severity_change')->where('subject_id', (string) $event->id)->exists())->toBeTrue();
});

it('requires a justification comment when changing severity', function () {
    $user = severityTriageUser();
    $event = SecurityEvent::factory()->create();

    expect(fn () => app(SeverityChanger::class)->change($event, $user, EventSeverity::Medium, 'Too short'))
        ->toThrow(ValidationException::class);
});

it('hides the change severity action from a reader', function () {
    $user = severityReaderUser();
    $event = SecurityEvent::factory()->create();

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertDontSee('Change severity');
});

function severityTriageUser(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Triage']);

    return $user;
}

function severityReaderUser(): User
{
    return User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
}
