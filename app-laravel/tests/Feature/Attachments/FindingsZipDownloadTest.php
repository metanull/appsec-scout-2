<?php

use App\Assets\AttachmentService;
use App\Assets\FindingsZipBuilder;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('zips both vulnerability and secret attachments for a single container', function () {
    $container = SecurityContainer::factory()->create(['name' => 'payments-api']);

    app(AttachmentService::class)->attachTo($container, 'vulnerabilities', 'application/json', 'vuln.json', '{"vuln":true}');
    app(AttachmentService::class)->attachTo($container, 'secrets', 'application/json', 'secrets.json', '{"secret":true}');

    $path = app(FindingsZipBuilder::class)->build($container);

    expect($path)->not()->toBeNull();

    $zip = new ZipArchive;
    $zip->open($path);

    expect($zip->numFiles)->toBe(2)
        ->and($zip->locateName('payments-api-vulnerabilities.sarif'))->not()->toBeFalse()
        ->and($zip->locateName('payments-api-secrets.sarif'))->not()->toBeFalse();

    $zip->close();
    @unlink($path);
});

it('zips findings from every descendant container for a software system', function () {
    $system = SoftwareSystem::factory()->create();
    $containerA = SecurityContainer::factory()->forSystem($system)->create(['name' => 'service-a']);
    $containerB = SecurityContainer::factory()->forSystem($system)->create(['name' => 'service-b']);

    app(AttachmentService::class)->attachTo($containerA, 'vulnerabilities', 'application/json', 'a.json', '{"a":true}');
    app(AttachmentService::class)->attachTo($containerB, 'secrets', 'application/json', 'b.json', '{"b":true}');

    $path = app(FindingsZipBuilder::class)->build($system);

    expect($path)->not()->toBeNull();

    $zip = new ZipArchive;
    $zip->open($path);
    expect($zip->numFiles)->toBe(2);
    $zip->close();
    @unlink($path);
});

it('zips findings from every descendant container for a software asset across systems', function () {
    $asset = SoftwareAsset::factory()->create();
    $systemA = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $systemB = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $containerA = SecurityContainer::factory()->forSystem($systemA)->create(['name' => 'service-a']);
    $containerB = SecurityContainer::factory()->forSystem($systemB)->create(['name' => 'service-b']);

    app(AttachmentService::class)->attachTo($containerA, 'vulnerabilities', 'application/json', 'a.json', '{"a":true}');
    app(AttachmentService::class)->attachTo($containerB, 'secrets', 'application/json', 'b.json', '{"b":true}');

    $path = app(FindingsZipBuilder::class)->build($asset);

    expect($path)->not()->toBeNull();

    $zip = new ZipArchive;
    $zip->open($path);
    expect($zip->numFiles)->toBe(2);
    $zip->close();
    @unlink($path);
});

it('returns null when there is nothing to zip', function () {
    $container = SecurityContainer::factory()->create();

    expect(app(FindingsZipBuilder::class)->build($container))->toBeNull();
});

it('downloads a findings zip for a container', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $container = SecurityContainer::factory()->create(['name' => 'payments-api']);
    app(AttachmentService::class)->attachTo($container, 'vulnerabilities', 'application/json', 'vuln.json', '{"vuln":true}');

    $this->actingAs($user)
        ->get(route('security-containers.findings.download', ['container' => $container->id]))
        ->assertOk()
        ->assertHeader('Content-Disposition');
});

it('downloads a findings zip for a software system', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $system = SoftwareSystem::factory()->create(['name' => 'payments-service']);
    $container = SecurityContainer::factory()->forSystem($system)->create();
    app(AttachmentService::class)->attachTo($container, 'secrets', 'application/json', 'secrets.json', '{"secret":true}');

    $this->actingAs($user)
        ->get(route('software-systems.findings.download', ['system' => $system->id]))
        ->assertOk()
        ->assertHeader('Content-Disposition');
});

it('downloads a findings zip for a software asset', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $asset = SoftwareAsset::factory()->create(['name' => 'Payments Platform']);
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $container = SecurityContainer::factory()->forSystem($system)->create();
    app(AttachmentService::class)->attachTo($container, 'vulnerabilities', 'application/json', 'vuln.json', '{"vuln":true}');

    $this->actingAs($user)
        ->get(route('assets.findings.download', ['asset' => $asset->id]))
        ->assertOk()
        ->assertHeader('Content-Disposition');
});

it('returns 404 for the findings zip download when there is nothing to zip', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $container = SecurityContainer::factory()->create();

    $this->actingAs($user)
        ->get(route('security-containers.findings.download', ['container' => $container->id]))
        ->assertNotFound();
});

it('requires the alerts.view permission for the findings zip download', function () {
    $container = SecurityContainer::factory()->create();

    $userWithoutPermission = User::factory()->create();
    $userWithoutPermission->syncRoles([]);

    $this->actingAs($userWithoutPermission)
        ->get(route('security-containers.findings.download', ['container' => $container->id]))
        ->assertForbidden();
});
