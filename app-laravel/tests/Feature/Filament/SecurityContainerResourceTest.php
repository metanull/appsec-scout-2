<?php

use App\Filament\Resources\SecurityContainerResource\Pages\ListSecurityContainers;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('sorts containers by their software system name', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $systemA = SoftwareSystem::factory()->create(['name' => 'Alpha System']);
    $systemZ = SoftwareSystem::factory()->create(['name' => 'Zeta System']);
    $containerInAlpha = SecurityContainer::factory()->forSystem($systemA)->create(['name' => 'alpha-container']);
    $containerInZeta = SecurityContainer::factory()->forSystem($systemZ)->create(['name' => 'zeta-container']);

    Livewire::actingAs($user)
        ->test(ListSecurityContainers::class)
        ->sortTable('softwareSystem.name')
        ->assertCanSeeTableRecords([$containerInAlpha, $containerInZeta], inOrder: true)
        ->sortTable('softwareSystem.name', 'desc')
        ->assertCanSeeTableRecords([$containerInZeta, $containerInAlpha], inOrder: true);
});

it('badge-colors the critical, high, and medium alert count columns', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $container = SecurityContainer::factory()->create();
    SecurityEvent::factory()->secret()->forContainer($container)->create();

    Livewire::actingAs($user)
        ->test(ListSecurityContainers::class)
        ->assertTableColumnStateSet('critical_events_count', 1, $container->fresh())
        ->assertTableColumnStateSet('high_events_count', 0, $container->fresh())
        ->assertTableColumnStateSet('medium_events_count', 0, $container->fresh());
});
