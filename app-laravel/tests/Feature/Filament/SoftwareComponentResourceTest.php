<?php

use App\Filament\Resources\SoftwareComponentResource;
use App\Filament\Resources\SoftwareComponentResource\Pages\ListSoftwareComponents;
use App\Filament\Resources\SoftwareComponentResource\Pages\ViewSoftwareComponent;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('lets readers view the dependency explorer', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $this->actingAs($user);

    expect(SoftwareComponentResource::canViewAny())->toBeTrue();
});

it('lists a component and links to its owning container', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $container = SecurityContainer::factory()->create(['name' => 'payments-api']);
    $component = $container->softwareComponents()->create([
        'name' => 'Jinja2',
        'version' => '3.1.4',
        'ecosystem' => 'pip',
        'purl' => 'pkg:pypi/jinja2@3.1.4',
    ]);

    Livewire::actingAs($user)
        ->test(ListSoftwareComponents::class)
        ->assertCanSeeTableRecords([$component])
        ->assertSee('Jinja2')
        ->assertSee('payments-api');

    expect(SoftwareComponentResource::getUrl('view', ['record' => $component]))->toBeString();
});

it('shows the asset, system, and container columns on the list', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $asset = SoftwareAsset::factory()->create(['name' => 'Payments Platform']);
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id, 'name' => 'payments-service']);
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'payments-api']);

    $component = $container->softwareComponents()->create([
        'name' => 'Jinja2',
        'purl' => 'pkg:pypi/jinja2@3.1.4',
        'software_system_id' => $system->id,
        'software_asset_id' => $asset->id,
    ]);

    Livewire::actingAs($user)
        ->test(ListSoftwareComponents::class)
        ->assertCanSeeTableRecords([$component])
        ->assertSee('Payments Platform')
        ->assertSee('payments-service')
        ->assertSee('payments-api');
});

it('groups the list by component name', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $containerA = SecurityContainer::factory()->create(['name' => 'service-a']);
    $containerB = SecurityContainer::factory()->create(['name' => 'service-b']);

    $componentA = $containerA->softwareComponents()->create([
        'name' => 'Jinja2',
        'version' => '3.1.4',
        'purl' => 'pkg:pypi/jinja2@3.1.4',
    ]);
    $componentB = $containerB->softwareComponents()->create([
        'name' => 'Jinja2',
        'version' => '3.1.3',
        'purl' => 'pkg:pypi/jinja2@3.1.3',
    ]);

    Livewire::actingAs($user)
        ->test(ListSoftwareComponents::class)
        ->set('tableGrouping', 'name')
        ->assertCanSeeTableRecords([$componentA, $componentB])
        ->assertSee('Jinja2');
});

it('groups the list by used by', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $container = SecurityContainer::factory()->create(['name' => 'payments-api']);
    $component = $container->softwareComponents()->create([
        'name' => 'Jinja2',
        'purl' => 'pkg:pypi/jinja2@3.1.4',
    ]);

    Livewire::actingAs($user)
        ->test(ListSoftwareComponents::class)
        ->set('tableGrouping', '_used_by')
        ->assertCanSeeTableRecords([$component])
        ->assertSee('Container: payments-api');
});

it('shows the used by field first on the view page', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $container = SecurityContainer::factory()->create(['name' => 'payments-api']);
    $component = $container->softwareComponents()->create([
        'name' => 'Jinja2',
        'purl' => 'pkg:pypi/jinja2@3.1.4',
    ]);

    Livewire::actingAs($user)
        ->test(ViewSoftwareComponent::class, ['record' => $component->getKey()])
        ->assertSee('Container: payments-api');
});
