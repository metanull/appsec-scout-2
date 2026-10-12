<?php

use App\Assets\SoftwareAssetService;
use App\Audit\AuditLog;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use App\Models\User;

/** @return array{finding: LocalFinding, component: SoftwareComponent} */
function scanContainerOf(SoftwareSystem $system, string $suffix): array
{
    $container = SecurityContainer::factory()->forSystem($system)->create();

    $finding = LocalFinding::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $system->id,
        'software_asset_id' => $system->software_asset_id,
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-' . $suffix,
        'title' => 'Finding ' . $suffix,
        'file_path' => 'requirements.txt',
    ]);

    $component = SoftwareComponent::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $system->id,
        'software_asset_id' => $system->software_asset_id,
        'name' => 'component-' . $suffix,
        'purl' => 'pkg:pypi/component-' . $suffix . '@1.0.0',
    ]);

    return ['finding' => $finding, 'component' => $component];
}

it('links and unlinks a software system with an audit trail', function () {
    $author = User::factory()->create();
    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create();

    $service = app(SoftwareAssetService::class);

    $linked = $service->attach($asset, $system, $author)->system;

    expect($linked->software_asset_id)->toBe($asset->id)
        ->and(AuditLog::query()->where('action', 'software_system_linked_to_asset')->exists())->toBeTrue();

    $unlinked = $service->detach($linked, $author)->system;

    expect($unlinked->software_asset_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'software_system_unlinked_from_asset')->exists())->toBeTrue();
});

it('moves a software system between assets', function () {
    $author = User::factory()->create();
    $assetA = SoftwareAsset::factory()->create();
    $assetB = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create();

    $service = app(SoftwareAssetService::class);

    $service->attach($assetA, $system, $author);
    $service->attach($assetB, $system, $author);

    expect($system->fresh()->software_asset_id)->toBe($assetB->id)
        ->and($assetA->fresh()->softwareSystems()->count())->toBe(0)
        ->and($assetB->fresh()->softwareSystems()->count())->toBe(1);
});

it('moves the system\'s scanned findings and components to the new asset', function () {
    $assetA = SoftwareAsset::factory()->create();
    $assetB = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $assetA->id]);
    $other = SoftwareSystem::factory()->create(['software_asset_id' => $assetA->id]);

    $moved = scanContainerOf($system, '0001');
    $untouched = scanContainerOf($other, '0002');

    app(SoftwareAssetService::class)->attach($assetB, $system);

    expect($moved['finding']->fresh()?->software_asset_id)->toBe($assetB->id)
        ->and($moved['component']->fresh()?->software_asset_id)->toBe($assetB->id)
        ->and($untouched['finding']->fresh()?->software_asset_id)->toBe($assetA->id)
        ->and($untouched['component']->fresh()?->software_asset_id)->toBe($assetA->id)
        ->and($assetA->localFindings()->pluck('id')->all())->toBe([$untouched['finding']->id])
        ->and($assetB->localFindings()->pluck('id')->all())->toBe([$moved['finding']->id]);

    $audit = AuditLog::query()->where('action', 'software_system_linked_to_asset')->sole();

    expect($audit->payload_json['restamped'])->toBe(['local_findings' => 1, 'software_components' => 1]);
});

it('clears the asset from the system\'s scanned findings and components when it is unlinked', function () {
    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);

    $scanned = scanContainerOf($system, '0003');

    app(SoftwareAssetService::class)->detach($system);

    expect($scanned['finding']->fresh()?->software_asset_id)->toBeNull()
        ->and($scanned['component']->fresh()?->software_asset_id)->toBeNull()
        ->and($asset->localFindings()->count())->toBe(0)
        ->and($asset->softwareComponents()->count())->toBe(0);
});

it('repairs stale stamps left by earlier links through the backfill command', function () {
    $assetA = SoftwareAsset::factory()->create();
    $assetB = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $assetA->id]);

    $stale = scanContainerOf($system, '0004');

    // Simulate a move made before re-stamping existed: only the system was re-pointed.
    $system->forceFill(['software_asset_id' => $assetB->id])->save();

    $this->artisan('assets:restamp-hierarchy')
        ->expectsOutput('Re-stamped software_asset_id on 1 local finding(s) and 1 software component(s).')
        ->assertSuccessful();

    expect($stale['finding']->fresh()?->software_asset_id)->toBe($assetB->id)
        ->and($stale['component']->fresh()?->software_asset_id)->toBe($assetB->id)
        ->and(AuditLog::query()->where('action', 'software_asset_hierarchy_restamped')->exists())->toBeTrue();

    $this->artisan('assets:restamp-hierarchy')
        ->expectsOutput('Re-stamped software_asset_id on 0 local finding(s) and 0 software component(s).')
        ->assertSuccessful();
});
