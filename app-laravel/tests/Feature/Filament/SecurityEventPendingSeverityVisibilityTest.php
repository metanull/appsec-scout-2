<?php

use App\Filament\Resources\SecurityEventResource;
use App\Models\Enums\EventSeverity;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('keeps the Pending Sync section visible for a resolved local-only severity annotation', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Triage']);

    $event = SecurityEvent::factory()->create([
        'pending_severity' => EventSeverity::Critical,
        'is_dirty' => false,
    ]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('Pending Sync')
        ->assertSee('Pending severity');
});

it('hides the Pending Sync section when there is nothing pending at all', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Triage']);

    $event = SecurityEvent::factory()->create([
        'pending_state' => null,
        'pending_severity' => null,
        'is_dirty' => false,
    ]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertDontSee('Pending Sync');
});
