<?php

use App\Assets\AttachmentService;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;

function trivyFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/Trivy/{$name}"));
}

function staticAnalysisFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/StaticAnalysis/{$name}"));
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

it('parses a code-quality-dotnet attachment into local findings', function () {
    $container = SecurityContainer::factory()->create();

    app(AttachmentService::class)->attachTo(
        $container,
        'code-quality-dotnet',
        'application/json',
        'dotnet.sarif',
        staticAnalysisFixture('roslynator-sample.json'),
    );

    $finding = LocalFinding::query()->where('owner_id', $container->id)->firstOrFail();

    expect($finding->kind)->toBe(LocalFinding::KIND_CODE_QUALITY)
        ->and($finding->rule_id)->toBe('CA2100')
        ->and($finding->severity)->toBe('MEDIUM')
        ->and($finding->file_path)->toBe('src/UserRepository.cs');
});

it('parses a code-quality-java attachment into local findings', function () {
    $container = SecurityContainer::factory()->create();

    app(AttachmentService::class)->attachTo(
        $container,
        'code-quality-java',
        'application/json',
        'java.sarif',
        staticAnalysisFixture('spotbugs-sample.json'),
    );

    $finding = LocalFinding::query()->where('owner_id', $container->id)->firstOrFail();

    expect($finding->kind)->toBe(LocalFinding::KIND_CODE_QUALITY)
        ->and($finding->rule_id)->toBe('SQL_INJECTION_JDBC')
        ->and($finding->severity)->toBe('HIGH')
        ->and($finding->file_path)->toBe('src/main/java/com/example/UserDao.java');
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

it('marks a software component removed when it disappears from a re-scan, and un-marks it if it reappears', function () {
    $container = SecurityContainer::factory()->create();
    $service = app(AttachmentService::class);

    $service->attachTo($container, 'sbom', 'application/json', 'first.json', minimalCycloneDx([
        'pkg:nuget/PackageA@1.0.0',
        'pkg:nuget/PackageB@1.0.0',
    ]));

    expect(SoftwareComponent::query()->where('owner_id', $container->id)->whereNull('removed_at')->count())->toBe(2);

    $service->attachTo($container, 'sbom', 'application/json', 'second.json', minimalCycloneDx([
        'pkg:nuget/PackageA@1.0.0',
    ]));

    $componentA = SoftwareComponent::query()->where('owner_id', $container->id)->where('purl', 'pkg:nuget/PackageA@1.0.0')->firstOrFail();
    $componentB = SoftwareComponent::query()->where('owner_id', $container->id)->where('purl', 'pkg:nuget/PackageB@1.0.0')->firstOrFail();

    expect($componentA->removed_at)->toBeNull()
        ->and($componentB->removed_at)->not->toBeNull();

    $service->attachTo($container, 'sbom', 'application/json', 'third.json', minimalCycloneDx([
        'pkg:nuget/PackageA@1.0.0',
        'pkg:nuget/PackageB@1.0.0',
    ]));

    expect($componentB->fresh()->removed_at)->toBeNull();
});

it('auto-resolves a local finding that disappears from a re-scan, without overriding a manually-set status', function () {
    $container = SecurityContainer::factory()->create();
    $service = app(AttachmentService::class);

    $service->attachTo($container, 'vulnerabilities', 'application/json', 'first.json', minimalVulnerabilitySarif([
        ['ruleId' => 'CVE-2024-0001', 'filePath' => 'requirements.txt', 'startLine' => 1],
        ['ruleId' => 'CVE-2024-0002', 'filePath' => 'requirements.txt', 'startLine' => 2],
    ]));

    $findingToDismiss = LocalFinding::query()->where('owner_id', $container->id)->where('rule_id', 'CVE-2024-0001')->firstOrFail();
    $findingToResolve = LocalFinding::query()->where('owner_id', $container->id)->where('rule_id', 'CVE-2024-0002')->firstOrFail();

    // An operator already triaged this one away from the default Open status before the re-scan.
    $findingToDismiss->forceFill(['status' => EventState::Dismissed])->save();

    $service->attachTo($container, 'vulnerabilities', 'application/json', 'second.json', minimalVulnerabilitySarif([]));

    expect($findingToDismiss->fresh()->status)->toBe(EventState::Dismissed)
        ->and($findingToResolve->fresh()->status)->toBe(EventState::Resolved);
});

function minimalCycloneDx(array $purls): string
{
    return json_encode([
        'bomFormat' => 'CycloneDX',
        'specVersion' => '1.7',
        'components' => array_map(fn (string $purl, int $index): array => [
            'bom-ref' => "component-{$index}",
            'type' => 'library',
            'name' => "Package{$index}",
            'version' => '1.0.0',
            'purl' => $purl,
        ], $purls, array_keys($purls)),
    ], JSON_THROW_ON_ERROR);
}

function minimalVulnerabilitySarif(array $findings): string
{
    return json_encode([
        'version' => '2.1.0',
        'runs' => [[
            'tool' => [
                'driver' => [
                    'name' => 'Trivy',
                    'rules' => array_map(fn (array $finding): array => [
                        'id' => $finding['ruleId'],
                        'shortDescription' => ['text' => "{$finding['ruleId']} description"],
                    ], $findings),
                ],
            ],
            'results' => array_map(fn (array $finding): array => [
                'ruleId' => $finding['ruleId'],
                'level' => 'warning',
                'message' => ['text' => "Severity: MEDIUM\nLink: [{$finding['ruleId']}](https://example.test/{$finding['ruleId']})"],
                'locations' => [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => $finding['filePath']],
                        'region' => ['startLine' => $finding['startLine']],
                    ],
                ]],
            ], $findings),
        ]],
    ], JSON_THROW_ON_ERROR);
}
