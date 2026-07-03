<?php

use App\Filament\Resources\SoftwareComponentResource;
use App\Filament\Resources\SoftwareComponentResource\Pages\ListSoftwareComponents;
use App\Models\SecurityContainer;
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
        ->assertSee('Container: payments-api');

    expect(SoftwareComponentResource::getUrl('view', ['record' => $component]))->toBeString();
});
