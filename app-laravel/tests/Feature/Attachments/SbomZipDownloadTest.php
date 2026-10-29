<?php

use App\Assets\AttachmentService;
use App\Assets\SbomZipBuilder;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    withoutDependencyTrackPush();
    (new RolePermissionSeeder)->run();
});

it('zips every descendant container sbom for a software system', function () {
    $system = SoftwareSystem::factory()->create();
    $containerA = SecurityContainer::factory()->forSystem($system)->create(['name' => 'service-a']);
    $containerB = SecurityContainer::factory()->forSystem($system)->create(['name' => 'service-b']);

    app(AttachmentService::class)->attachTo($containerA, 'sbom', 'application/json', 'a.json', '{"bomFormat":"CycloneDX","a":true}');
    app(AttachmentService::class)->attachTo($containerB, 'sbom', 'application/json', 'b.json', '{"bomFormat":"CycloneDX","b":true}');

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

    app(AttachmentService::class)->attachTo($containerA, 'sbom', 'application/json', 'a.json', '{"bomFormat":"CycloneDX","a":true}');
    app(AttachmentService::class)->attachTo($containerB, 'sbom', 'application/json', 'b.json', '{"bomFormat":"CycloneDX","b":true}');

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
    $user = User::factory()->withTotp()->create();
    $user->syncRoles(['Reader']);

    $system = SoftwareSystem::factory()->create(['name' => 'payments-service']);
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'payments-api']);
    app(AttachmentService::class)->attachTo($container, 'sbom', 'application/json', 'sbom.json', '{"bomFormat":"CycloneDX","components":[]}');

    $this->actingAs($user)
        ->get(route('software-systems.sbom.download', ['system' => $system->id]))
        ->assertOk()
        ->assertHeader('Content-Disposition');
});

it('downloads a zip of sboms for a software asset', function () {
    $user = User::factory()->withTotp()->create();
    $user->syncRoles(['Reader']);

    $asset = SoftwareAsset::factory()->create(['name' => 'Payments Platform']);
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'payments-api']);
    app(AttachmentService::class)->attachTo($container, 'sbom', 'application/json', 'sbom.json', '{"bomFormat":"CycloneDX","components":[]}');

    $this->actingAs($user)
        ->get(route('assets.sbom.download', ['asset' => $asset->id]))
        ->assertOk()
        ->assertHeader('Content-Disposition');
});

it('returns 404 for the zip download when there is nothing to zip', function () {
    $user = User::factory()->withTotp()->create();
    $user->syncRoles(['Reader']);

    $system = SoftwareSystem::factory()->create();

    $this->actingAs($user)
        ->get(route('software-systems.sbom.download', ['system' => $system->id]))
        ->assertNotFound();
});

it('requires the alerts.view permission for the zip download', function () {
    $system = SoftwareSystem::factory()->create();

    $userWithoutPermission = User::factory()->withTotp()->create();
    $userWithoutPermission->syncRoles([]);

    $this->actingAs($userWithoutPermission)
        ->get(route('software-systems.sbom.download', ['system' => $system->id]))
        ->assertForbidden();
});

it('zips each container\'s lock-file and committed-archive SBOMs merged, including archive-only containers', function () {
    $system = SoftwareSystem::factory()->create();
    $both = SecurityContainer::factory()->forSystem($system)->create(['name' => 'phoenix-jboss']);
    $archivesOnly = SecurityContainer::factory()->forSystem($system)->create(['name' => 'legacy-ear']);
    $service = app(AttachmentService::class);
    $sbom = fn (string $purl): string => json_encode(['bomFormat' => 'CycloneDX', 'components' => [['bom-ref' => $purl, 'type' => 'library', 'name' => $purl, 'purl' => $purl]]], JSON_THROW_ON_ERROR);

    $service->attachTo($both, 'sbom', 'application/json', 'fs.json', $sbom('pkg:maven/org.example/app@1.0.0'));
    $service->attachTo($both, 'sbom-archives', 'application/json', 'archives.json', $sbom('pkg:maven/org.apache.struts/struts2-core@2.3.16.3'));
    $service->attachTo($archivesOnly, 'sbom-archives', 'application/json', 'archives.json', $sbom('pkg:maven/commons-collections/commons-collections@3.2'));

    $path = app(SbomZipBuilder::class)->build($system);

    $zip = new ZipArchive;
    $zip->open($path);
    $components = fn (string $entry): array => array_column(json_decode((string) $zip->getFromName($entry), true, 512, JSON_THROW_ON_ERROR)['components'], 'purl');

    expect($zip->numFiles)->toBe(2)
        ->and($components('phoenix-jboss.json'))->toBe(['pkg:maven/org.example/app@1.0.0', 'pkg:maven/org.apache.struts/struts2-core@2.3.16.3'])
        ->and($components('legacy-ear.json'))->toBe(['pkg:maven/commons-collections/commons-collections@3.2']);

    $zip->close();
    @unlink($path);
});

it('downloads a container\'s merged SBOM', function () {
    $user = User::factory()->withTotp()->create();
    $user->syncRoles(['Reader']);

    $container = SecurityContainer::factory()->create(['name' => 'Phoenix JBoss']);
    $service = app(AttachmentService::class);
    $service->attachTo($container, 'sbom', 'application/json', 'fs.json', '{"bomFormat":"CycloneDX","components":[{"bom-ref":"a","type":"library","name":"a","purl":"pkg:maven/org.example/a@1.0.0"}]}');
    $service->attachTo($container, 'sbom-archives', 'application/json', 'archives.json', '{"bomFormat":"CycloneDX","components":[{"bom-ref":"b","type":"library","name":"b","purl":"pkg:maven/org.example/b@1.0.0"}]}');

    $response = $this->actingAs($user)->get(route('security-containers.sbom.download', ['container' => $container->id]));

    $response->assertOk()->assertDownload('phoenix-jboss.cdx.json');
    expect(array_column(json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR)['components'], 'purl'))
        ->toBe(['pkg:maven/org.example/a@1.0.0', 'pkg:maven/org.example/b@1.0.0']);
});

it('returns 404 for a container SBOM download without an SBOM, and requires alerts.view', function () {
    $reader = User::factory()->withTotp()->create();
    $reader->syncRoles(['Reader']);
    $nobody = User::factory()->withTotp()->create();
    $nobody->syncRoles([]);
    $container = SecurityContainer::factory()->create();

    $this->actingAs($reader)->get(route('security-containers.sbom.download', ['container' => $container->id]))->assertNotFound();
    $this->actingAs($nobody)->get(route('security-containers.sbom.download', ['container' => $container->id]))->assertForbidden();
});
