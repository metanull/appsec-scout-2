<?php

use App\Filament\Resources\Shared\RelationManagers\LocalFindingsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\SoftwareComponentsRelationManager;
use App\Filament\Resources\SoftwareSystemResource\Pages\ViewSoftwareSystem;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('renders the dependencies tab with a recorded software component', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $container = SecurityContainer::factory()->create();
    $container->softwareComponents()->create([
        'name' => 'Jinja2',
        'version' => '3.1.4',
        'ecosystem' => 'pip',
        'purl' => 'pkg:pypi/jinja2@3.1.4',
    ]);

    Livewire::actingAs($user)
        ->test(SoftwareComponentsRelationManager::class, [
            'ownerRecord' => $container,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->call('loadTable')
        ->assertSee('Jinja2')
        ->assertSee('3.1.4');
});

it('renders the local findings tab with severity and correlation status', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $container = SecurityContainer::factory()->create();
    $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-56201',
        'title' => 'Jinja sandbox breakout',
        'severity' => 'MEDIUM',
        'file_path' => 'requirements.txt',
        'start_line' => 8,
        'package_name' => 'Jinja2',
        'package_version' => '3.1.4',
    ]);

    Livewire::actingAs($user)
        ->test(LocalFindingsRelationManager::class, [
            'ownerRecord' => $container,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->call('loadTable')
        ->assertSee('Jinja sandbox breakout')
        ->assertSee('MEDIUM');
});
