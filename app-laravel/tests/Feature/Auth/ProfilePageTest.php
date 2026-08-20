<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('hides the password section for a passwordless federated user', function () {
    $user = User::factory()->create([
        'email' => 'federated@example.com',
        'password' => null,
        'entra_object_id' => 'oid-profile-1',
    ]);

    $this->actingAs($user)
        ->withSession(['entra.authenticated' => true])
        ->get('/profile')
        ->assertOk()
        ->assertDontSee('New password')
        ->assertDontSee('Current password');
});

it('still shows the password section for a user with a password', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertSee('New password');
});

it('lets a passwordless federated user save their name', function () {
    $user = User::factory()->create([
        'email' => 'renameme@example.com',
        'password' => null,
        'entra_object_id' => 'oid-profile-2',
    ]);

    $this->actingAs($user)->withSession(['entra.authenticated' => true]);

    Livewire::test(EditProfile::class)
        ->fillForm(['name' => 'Renamed User'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()?->name)->toBe('Renamed User');
});
