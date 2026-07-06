<?php

use App\Assets\AttachmentService;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;

function trivyFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/Trivy/{$name}"));
}

it('parses an sbom attachment into software components on the owning container', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    app(AttachmentService::class)->attachTo(
        $container,
        'sbom',
        'application/json',
        'sbom.cdx.json',
        trivyFixture('cyclonedx-sample.json'),
    );

    $components = SoftwareComponent::query()
        ->where('owner_type', SecurityContainer::class)
        ->where('owner_id', $container->id)
        ->get();

    expect($components)->toHaveCount(4)
        ->and($components->pluck('name')->all())->toContain('System.DirectoryServices.Protocols');
});

it('re-scanning updates the same software component row instead of duplicating it', function () {
    $container = SecurityContainer::factory()->create();
    $service = app(AttachmentService::class);

    $service->attachTo($container, 'sbom', 'application/json', 'first.json', trivyFixture('cyclonedx-sample.json'));
    $service->attachTo($container, 'sbom', 'application/json', 'second.json', trivyFixture('cyclonedx-sample.json'));

    expect(SoftwareComponent::query()->where('owner_id', $container->id)->count())->toBe(4);
});

it('parses a vulnerabilities attachment into local findings', function () {
    $container = SecurityContainer::factory()->create();

    app(AttachmentService::class)->attachTo(
        $container,
        'vulnerabilities',
        'application/json',
        'vuln.sarif.json',
        trivyFixture('vuln-sarif-sample.json'),
    );

    $finding = LocalFinding::query()->where('owner_id', $container->id)->firstOrFail();

    expect($finding->kind)->toBe(LocalFinding::KIND_VULNERABILITY)
        ->and($finding->rule_id)->toBe('CVE-2024-56201')
        ->and($finding->package_name)->toBe('Jinja2')
        ->and($finding->attachment)->not()->toBeNull();
});

it('parses a secrets attachment into local findings', function () {
    $container = SecurityContainer::factory()->create();

    app(AttachmentService::class)->attachTo(
        $container,
        'secrets',
        'application/json',
        'secrets.sarif.json',
        trivyFixture('secret-sarif-sample.json'),
    );

    $finding = LocalFinding::query()->where('owner_id', $container->id)->firstOrFail();

    expect($finding->kind)->toBe(LocalFinding::KIND_SECRET)
        ->and($finding->rule_id)->toBe('github-pat')
        ->and($finding->file_path)->toBe('config.php');
});

it('does not parse attachments of other kinds', function () {
    $container = SecurityContainer::factory()->create();

    app(AttachmentService::class)->attachTo($container, 'manual', 'text/plain', 'notes.txt', 'just some notes');

    expect(SoftwareComponent::query()->where('owner_id', $container->id)->count())->toBe(0)
        ->and(LocalFinding::query()->where('owner_id', $container->id)->count())->toBe(0);
});

it('cascades deleting components and findings when the owning container is deleted', function () {
    $container = SecurityContainer::factory()->create();
    $service = app(AttachmentService::class);

    $service->attachTo($container, 'sbom', 'application/json', 'sbom.json', trivyFixture('cyclonedx-sample.json'));
    $service->attachTo($container, 'vulnerabilities', 'application/json', 'vuln.json', trivyFixture('vuln-sarif-sample.json'));

    $container->delete();

    expect(SoftwareComponent::query()->where('owner_id', $container->id)->count())->toBe(0)
        ->and(LocalFinding::query()->where('owner_id', $container->id)->count())->toBe(0);
});

it('stamps the system and asset hierarchy onto ingested software components', function () {
    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $container = SecurityContainer::factory()->forSystem($system)->create();

    app(AttachmentService::class)->attachTo(
        $container,
        'sbom',
        'application/json',
        'sbom.cdx.json',
        trivyFixture('cyclonedx-sample.json'),
    );

    $component = SoftwareComponent::query()->where('owner_id', $container->id)->firstOrFail();

    expect($component->software_system_id)->toBe($system->id)
        ->and($component->software_asset_id)->toBe($asset->id)
        ->and($component->softwareSystem->is($system))->toBeTrue()
        ->and($component->softwareAsset->is($asset))->toBeTrue();
});

it('stamps the system and asset hierarchy onto ingested local findings', function () {
    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $container = SecurityContainer::factory()->forSystem($system)->create();

    app(AttachmentService::class)->attachTo(
        $container,
        'vulnerabilities',
        'application/json',
        'vuln.sarif.json',
        trivyFixture('vuln-sarif-sample.json'),
    );

    $finding = LocalFinding::query()->where('owner_id', $container->id)->firstOrFail();

    expect($finding->software_system_id)->toBe($system->id)
        ->and($finding->software_asset_id)->toBe($asset->id)
        ->and($finding->softwareSystem->is($system))->toBeTrue()
        ->and($finding->softwareAsset->is($asset))->toBeTrue();
});

it('leaves the asset hierarchy null when the system has no asset', function () {
    $container = SecurityContainer::factory()->create();

    app(AttachmentService::class)->attachTo(
        $container,
        'sbom',
        'application/json',
        'sbom.cdx.json',
        trivyFixture('cyclonedx-sample.json'),
    );

    $component = SoftwareComponent::query()->where('owner_id', $container->id)->firstOrFail();

    expect($component->software_system_id)->toBe($container->software_system_id)
        ->and($component->software_asset_id)->toBeNull();
});
