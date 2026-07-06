<?php

use App\Assets\AttachmentService;
use App\Assets\SbomZipBuilder;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('zips every descendant container sbom for a software system', function () {
    $system = SoftwareSystem::factory()->create();
    $containerA = SecurityContainer::factory()->forSystem($system)->create(['name' => 'service-a']);
    $containerB = SecurityContainer::factory()->forSystem($system)->create(['name' => 'service-b']);

    app(AttachmentService::class)->attachTo($containerA, 'sbom', 'application/json', 'a.json', '{"a":true}');
    app(AttachmentService::class)->attachTo($containerB, 'sbom', 'application/json', 'b.json', '{"b":true}');

    $path = app(SbomZipBuilder::class)->build($system);

    expect($path)->not()->toBeNull();

    $zip = new ZipArchive;
    $zip->open($path);

    expect($zip->numFiles)->toBe(2)
        ->and($zip->locateName('service-a.json'))->not()->toBeFalse()
        ->and($zip->locateName('service-b.json'))->not()->toBeFalse();

    $zip->close();
    @unlink($path);
});

it('zips every descendant container sbom for a software asset across systems', function () {
    $asset = SoftwareAsset::factory()->create();
    $systemA = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $systemB = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $containerA = SecurityContainer::factory()->forSystem($systemA)->create(['name' => 'service-a']);
    $containerB = SecurityContainer::factory()->forSystem($systemB)->create(['name' => 'service-b']);

    app(AttachmentService::class)->attachTo($containerA, 'sbom', 'application/json', 'a.json', '{"a":true}');
    app(AttachmentService::class)->attachTo($containerB, 'sbom', 'application/json', 'b.json', '{"b":true}');

    $path = app(SbomZipBuilder::class)->build($asset);

    expect($path)->not()->toBeNull();

    $zip = new ZipArchive;
    $zip->open($path);
    expect($zip->numFiles)->toBe(2);
    $zip->close();
    @unlink($path);
});

it('returns null when nothing to zip', function () {
    $system = SoftwareSystem::factory()->create();
    SecurityContainer::factory()->forSystem($system)->create();

    expect(app(SbomZipBuilder::class)->build($system))->toBeNull();
});

it('downloads a zip of sboms for a software system', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $system = SoftwareSystem::factory()->create(['name' => 'payments-service']);
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'payments-api']);
    app(AttachmentService::class)->attachTo($container, 'sbom', 'application/json', 'sbom.json', '{"components":[]}');

    $this->actingAs($user)
        ->get(route('software-systems.sbom.download', ['system' => $system->id]))
        ->assertOk()
        ->assertHeader('Content-Disposition');
});

it('downloads a zip of sboms for a software asset', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $asset = SoftwareAsset::factory()->create(['name' => 'Payments Platform']);
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'payments-api']);
    app(AttachmentService::class)->attachTo($container, 'sbom', 'application/json', 'sbom.json', '{"components":[]}');

    $this->actingAs($user)
        ->get(route('assets.sbom.download', ['asset' => $asset->id]))
        ->assertOk()
        ->assertHeader('Content-Disposition');
});

it('returns 404 for the zip download when there is nothing to zip', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $system = SoftwareSystem::factory()->create();

    $this->actingAs($user)
        ->get(route('software-systems.sbom.download', ['system' => $system->id]))
        ->assertNotFound();
});

it('requires the alerts.view permission for the zip download', function () {
    $system = SoftwareSystem::factory()->create();

    $userWithoutPermission = User::factory()->create();
    $userWithoutPermission->syncRoles([]);

    $this->actingAs($userWithoutPermission)
        ->get(route('software-systems.sbom.download', ['system' => $system->id]))
        ->assertForbidden();
});
