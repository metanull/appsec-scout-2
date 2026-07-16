<?php

use App\Filament\Resources\SoftwareSystemResource\Pages\ListSoftwareSystems;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('filters software systems by source', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $azdo = SoftwareSystem::factory()->create(['name' => 'Payments API', 'source_id' => 'azdo']);
    $asoc = SoftwareSystem::factory()->create(['name' => 'Legacy Billing', 'source_id' => 'asoc']);

    Livewire::actingAs($user)
        ->test(ListSoftwareSystems::class)
        ->filterTable('source_id', ['azdo'])
        ->assertCanSeeTableRecords([$azdo])
        ->assertCanNotSeeTableRecords([$asoc]);
});

it('badge-colors the critical, high, and medium alert count columns', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $system = SoftwareSystem::factory()->create(['name' => 'Payments API']);
    SecurityEvent::factory()->secret()->forSystem($system)->create();

    Livewire::actingAs($user)
        ->test(ListSoftwareSystems::class)
        ->assertTableColumnStateSet('critical_events_count', 1, $system->fresh())
        ->assertTableColumnStateSet('high_events_count', 0, $system->fresh())
        ->assertTableColumnStateSet('medium_events_count', 0, $system->fresh());
});
