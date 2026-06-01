<?php

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('renders pending sync page for sync role users', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Sync']);

    SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'is_dirty' => true,
        'pending_state' => EventState::Resolved,
        'pending_severity' => EventSeverity::Low,
    ]);

    $this->actingAs($user)
        ->get('/sync/pending')
        ->assertSuccessful()
        ->assertSee('Pending Sync')
        ->assertSee('azdo');
});
