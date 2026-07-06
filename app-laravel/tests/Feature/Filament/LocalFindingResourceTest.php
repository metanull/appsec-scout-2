<?php

use App\Filament\Resources\LocalFindingResource;
use App\Filament\Resources\LocalFindingResource\Pages\ListLocalFindings;
use App\Filament\Resources\LocalFindingResource\Pages\ViewLocalFinding;
use App\Models\LocalFinding;
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

it('lets readers view the local finding explorer', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $this->actingAs($user);

    expect(LocalFindingResource::canViewAny())->toBeTrue();
});

it('denies access without the alerts.view permission', function () {
    $user = User::factory()->create();
    $user->syncRoles([]);

    $this->actingAs($user);

    expect(LocalFindingResource::canViewAny())->toBeFalse();
});

it('lists a finding with the asset, system, and container columns', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $asset = SoftwareAsset::factory()->create(['name' => 'Payments Platform']);
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id, 'name' => 'payments-service']);
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'payments-api']);

    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-56201',
        'title' => 'Jinja sandbox breakout',
        'severity' => 'MEDIUM',
        'file_path' => 'requirements.txt',
        'start_line' => 8,
        'package_name' => 'Jinja2',
        'package_version' => '3.1.4',
        'software_system_id' => $system->id,
        'software_asset_id' => $asset->id,
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertCanSeeTableRecords([$finding])
        ->assertSee('Jinja sandbox breakout')
        ->assertSee('Payments Platform')
        ->assertSee('payments-service')
        ->assertSee('payments-api');

    expect(LocalFindingResource::getUrl('view', ['record' => $finding]))->toBeString();
});

it('shows the finding detail page including the correlated alert link', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $event = SecurityEvent::factory()->create();
    $container = SecurityContainer::factory()->create();

    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'github-pat',
        'title' => 'GitHub PAT committed',
        'file_path' => 'config.php',
        'correlated_security_event_id' => $event->id,
    ]);

    Livewire::actingAs($user)
        ->test(ViewLocalFinding::class, ['record' => $finding->getKey()])
        ->assertSee('GitHub PAT committed')
        ->assertSee('#' . $event->id);
});
