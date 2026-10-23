<?php

use App\Filament\Resources\Shared\RelationManagers\TrackerProjectLinksRelationManager;
use App\Filament\Resources\SoftwareSystemResource\Pages\ViewSoftwareSystem;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('shows tracker project link mutation controls to a user with work-items.link directly', function () {
    $user = enrolledTrackerLinkUser();
    $user->syncRoles([]);
    $user->givePermissionTo(['alerts.view', 'work-items.link']);

    $system = SoftwareSystem::factory()->create();

    Livewire::actingAs($user)
        ->test(TrackerProjectLinksRelationManager::class, [
            'ownerRecord' => $system,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->call('loadTable')
        ->assertSee('Add project link');
});

it('shows tracker project link mutation controls to the Admin role', function () {
    $user = enrolledTrackerLinkUser();
    $user->syncRoles(['Admin']);

    $system = SoftwareSystem::factory()->create();

    Livewire::actingAs($user)
        ->test(TrackerProjectLinksRelationManager::class, [
            'ownerRecord' => $system,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->call('loadTable')
        ->assertSee('Add project link');
});

it('hides tracker project link mutation controls from the Triage role', function () {
    $user = enrolledTrackerLinkUser();
    $user->syncRoles(['Triage']);

    $system = SoftwareSystem::factory()->create();

    Livewire::actingAs($user)
        ->test(TrackerProjectLinksRelationManager::class, [
            'ownerRecord' => $system,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->call('loadTable')
        ->assertDontSee('Add project link');
});

function enrolledTrackerLinkUser(): User
{
    return User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
}
