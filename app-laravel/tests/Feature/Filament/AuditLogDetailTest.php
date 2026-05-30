<?php

use App\Audit\AuditLog;
use App\Filament\Resources\AuditLogResource;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('renders the audit log detail infolist for a user with admin.audit', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);

    $log = AuditLog::query()->create([
        'user_id' => $user->id,
        'actor_kind' => 'user',
        'action' => 'alert.viewed',
        'subject_type' => 'App\\Models\\SecurityEvent',
        'subject_id' => '1',
        'payload_json' => ['old_state' => 'open', 'new_state' => 'resolved'],
    ]);

    $this->actingAs($user)
        ->get(AuditLogResource::getUrl('view', ['record' => $log]))
        ->assertOk()
        ->assertSee('alert.viewed')
        ->assertSee('Event')
        ->assertSee('Payload');
});

it('redacts sensitive keys in the audit log payload section', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);

    $log = AuditLog::query()->create([
        'user_id' => $user->id,
        'actor_kind' => 'system',
        'action' => 'token.issued',
        'subject_type' => null,
        'subject_id' => null,
        'payload_json' => ['token' => 'super-secret-value', 'description' => 'visible value'],
    ]);

    $this->actingAs($user)
        ->get(AuditLogResource::getUrl('view', ['record' => $log]))
        ->assertOk()
        ->assertSee('[redacted]', false)
        ->assertSee('visible value');
});
