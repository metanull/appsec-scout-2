<?php

use App\Assets\SoftwareAssetService;
use App\Audit\AuditLog;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;

it('links and unlinks a software system with an audit trail', function () {
    $author = User::factory()->create();
    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create();

    $service = app(SoftwareAssetService::class);

    $linked = $service->attach($asset, $system, $author);

    expect($linked->software_asset_id)->toBe($asset->id)
        ->and(AuditLog::query()->where('action', 'software_system_linked_to_asset')->exists())->toBeTrue();

    $unlinked = $service->detach($linked, $author);

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
