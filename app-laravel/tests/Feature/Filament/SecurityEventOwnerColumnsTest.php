<?php

use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SecurityEventResource\Pages\ListSecurityEvents;
use App\Filament\Resources\SoftwareAssetResource;
use App\Filament\Resources\SoftwareSystemResource;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('links the Asset, System, and Container columns to their owning resources', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $container = SecurityContainer::factory()->forSystem($system)->create();
    $event = SecurityEvent::factory()->forContainer($container)->create();

    $table = Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->instance()
        ->getTable();

    expect($table->getColumn('softwareSystem.softwareAsset.name')->record($event)->getUrl())
        ->toBe(SoftwareAssetResource::getUrl('view', ['record' => $asset]))
        ->and($table->getColumn('softwareSystem.name')->record($event)->getUrl())
        ->toBe(SoftwareSystemResource::getUrl('view', ['record' => $system]))
        ->and($table->getColumn('container.name')->record($event)->getUrl())
        ->toBe(SecurityContainerResource::getUrl('view', ['record' => $container]));
});

it('leaves the Asset and Container columns unlinked when the event has no asset or container', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $system = SoftwareSystem::factory()->create(['software_asset_id' => null]);
    $event = SecurityEvent::factory()->forSystem($system)->create(['container_id' => null]);

    $table = Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->instance()
        ->getTable();

    expect($table->getColumn('softwareSystem.softwareAsset.name')->record($event)->getUrl())
        ->toBeNull()
        ->and($table->getColumn('container.name')->record($event)->getUrl())
        ->toBeNull();
});
