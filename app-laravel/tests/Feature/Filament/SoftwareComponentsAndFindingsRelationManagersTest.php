<?php

use App\Assets\AttachmentService;
use App\Filament\Resources\Shared\RelationManagers\LocalFindingsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\SoftwareComponentsRelationManager;
use App\Filament\Resources\SoftwareSystemResource\Pages\ViewSoftwareSystem;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
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

it('rolls up dependencies and local findings from child containers on the system and asset tabs', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $container = SecurityContainer::factory()->forSystem($system)->create();

    SoftwareComponent::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $system->id,
        'software_asset_id' => $asset->id,
        'name' => 'Jinja2',
        'version' => '3.1.4',
        'purl' => 'pkg:pypi/jinja2@3.1.4',
    ]);

    LocalFinding::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $system->id,
        'software_asset_id' => $asset->id,
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-56201',
        'title' => 'Jinja sandbox breakout',
        'severity' => 'MEDIUM',
        'file_path' => 'requirements.txt',
    ]);

    Livewire::actingAs($user)
        ->test(SoftwareComponentsRelationManager::class, [
            'ownerRecord' => $system,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->call('loadTable')
        ->assertSee('Jinja2');

    Livewire::actingAs($user)
        ->test(SoftwareComponentsRelationManager::class, [
            'ownerRecord' => $asset,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->call('loadTable')
        ->assertSee('Jinja2');

    Livewire::actingAs($user)
        ->test(LocalFindingsRelationManager::class, [
            'ownerRecord' => $system,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->call('loadTable')
        ->assertSee('Jinja sandbox breakout');

    Livewire::actingAs($user)
        ->test(LocalFindingsRelationManager::class, [
            'ownerRecord' => $asset,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->call('loadTable')
        ->assertSee('Jinja sandbox breakout');
});

it('shows the asset, system, and container columns on the dependencies tab', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $asset = SoftwareAsset::factory()->create(['name' => 'Payments Platform']);
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id, 'name' => 'payments-service']);
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'payments-api']);

    SoftwareComponent::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $system->id,
        'software_asset_id' => $asset->id,
        'name' => 'Jinja2',
        'purl' => 'pkg:pypi/jinja2@3.1.4',
    ]);

    Livewire::actingAs($user)
        ->test(SoftwareComponentsRelationManager::class, [
            'ownerRecord' => $container,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->call('loadTable')
        ->assertSee('Payments Platform')
        ->assertSee('payments-service')
        ->assertSee('payments-api');
});

it('shows the download sbom action only when there is something to download', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $containerWithSbom = SecurityContainer::factory()->create();
    app(AttachmentService::class)->attachTo($containerWithSbom, 'sbom', 'application/json', 'sbom.json', '{"components":[]}');

    $containerWithoutSbom = SecurityContainer::factory()->create();

    Livewire::actingAs($user)
        ->test(SoftwareComponentsRelationManager::class, [
            'ownerRecord' => $containerWithSbom,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->assertTableActionVisible('downloadSbom');

    Livewire::actingAs($user)
        ->test(SoftwareComponentsRelationManager::class, [
            'ownerRecord' => $containerWithoutSbom,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->assertTableActionHidden('downloadSbom');
});

it('shows the download sbom action for a system when a descendant container has one', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();
    app(AttachmentService::class)->attachTo($container, 'sbom', 'application/json', 'sbom.json', '{"components":[]}');

    $emptySystem = SoftwareSystem::factory()->create();
    SecurityContainer::factory()->forSystem($emptySystem)->create();

    Livewire::actingAs($user)
        ->test(SoftwareComponentsRelationManager::class, [
            'ownerRecord' => $system,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->assertTableActionVisible('downloadSbom');

    Livewire::actingAs($user)
        ->test(SoftwareComponentsRelationManager::class, [
            'ownerRecord' => $emptySystem,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->assertTableActionHidden('downloadSbom');
});
