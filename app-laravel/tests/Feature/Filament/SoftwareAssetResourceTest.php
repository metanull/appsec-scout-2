<?php

use App\Filament\Resources\Shared\RelationManagers\TrackerProjectLinksRelationManager;
use App\Filament\Resources\SoftwareAssetResource;
use App\Filament\Resources\SoftwareAssetResource\Pages\ListSoftwareAssets;
use App\Filament\Resources\SoftwareAssetResource\Pages\ViewSoftwareAsset;
use App\Filament\Resources\SoftwareAssetResource\RelationManagers\SoftwareSystemsRelationManager;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('lets readers view but not create software assets', function () {
    $user = twoFactorUser();
    $user->syncRoles(['Reader']);

    $this->actingAs($user);

    expect(SoftwareAssetResource::canViewAny())->toBeTrue()
        ->and(SoftwareAssetResource::canCreate())->toBeFalse();
});

it('lets plan users create software assets and link software systems', function () {
    $user = twoFactorUser();
    $user->syncRoles(['Plan']);

    $this->actingAs($user);

    expect(SoftwareAssetResource::canCreate())->toBeTrue();

    $asset = SoftwareAsset::factory()->create(['name' => 'Payments Platform']);
    $system = SoftwareSystem::factory()->create(['name' => 'Payments AzDO Project']);

    Livewire::actingAs($user)
        ->test(SoftwareSystemsRelationManager::class, [
            'ownerRecord' => $asset,
            'pageClass' => ViewSoftwareAsset::class,
        ])
        ->call('loadTable')
        ->assertSee('Link software system')
        ->callTableAction('linkSystem', data: [
            'software_system_id' => $system->id,
        ]);

    expect($system->fresh()->software_asset_id)->toBe($asset->id);

    Livewire::actingAs($user)
        ->test(SoftwareSystemsRelationManager::class, [
            'ownerRecord' => $asset,
            'pageClass' => ViewSoftwareAsset::class,
        ])
        ->call('loadTable')
        ->callTableAction('unlink', $system->fresh());

    expect($system->fresh()->software_asset_id)->toBeNull();
});

it('does not register tracker project links at the asset level, since they are never resolved for an alert', function () {
    expect(SoftwareAssetResource::getRelations())->not->toContain(TrackerProjectLinksRelationManager::class);
});

it('filters software assets by whether they have open or critical alerts', function () {
    $user = twoFactorUser();
    $user->syncRoles(['Reader']);

    $withAlerts = SoftwareAsset::factory()->create(['name' => 'Payments Platform']);
    $systemWithAlerts = SoftwareSystem::factory()->create(['software_asset_id' => $withAlerts->id]);
    SecurityEvent::factory()->secret()->forSystem($systemWithAlerts)->create();

    $withoutAlerts = SoftwareAsset::factory()->create(['name' => 'Internal Tools']);
    SoftwareSystem::factory()->create(['software_asset_id' => $withoutAlerts->id]);

    Livewire::actingAs($user)
        ->test(ListSoftwareAssets::class)
        ->filterTable('has_open_events', true)
        ->assertCanSeeTableRecords([$withAlerts])
        ->assertCanNotSeeTableRecords([$withoutAlerts])
        ->removeTableFilter('has_open_events')
        ->filterTable('has_critical_events', true)
        ->assertCanSeeTableRecords([$withAlerts])
        ->assertCanNotSeeTableRecords([$withoutAlerts]);
});

it('badge-colors the critical and high alert count columns', function () {
    $user = twoFactorUser();
    $user->syncRoles(['Reader']);

    $asset = SoftwareAsset::factory()->create(['name' => 'Payments Platform']);
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    SecurityEvent::factory()->secret()->forSystem($system)->create();

    Livewire::actingAs($user)
        ->test(ListSoftwareAssets::class)
        ->assertTableColumnStateSet('critical_events_count', 1, $asset->fresh())
        ->assertTableColumnStateSet('high_events_count', 0, $asset->fresh());
});

function twoFactorUser(): User
{
    return User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
}
