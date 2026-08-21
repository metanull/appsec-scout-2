<?php

use App\Assets\AttachmentService;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
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

// SQLite applies in-statement duplicates row by row, so this guards the batch-dedup
// behavior that PostgreSQL enforces (see the phpunit.pgsql.xml CI run) — without it,
// the upsert fails there with "ON CONFLICT DO UPDATE command cannot affect row a second time".
it('ingests an sbom that lists the same purl twice, keeping the last occurrence', function () {
    $container = SecurityContainer::factory()->create();

    $payload = json_decode(trivyFixture('cyclonedx-sample.json'), true, 512, JSON_THROW_ON_ERROR);
    $duplicate = $payload['components'][0];
    $duplicate['licenses'] = [['license' => ['id' => 'Apache-2.0']]];
    $payload['components'][] = $duplicate;

    app(AttachmentService::class)->attachTo(
        $container,
        'sbom',
        'application/json',
        'sbom-duplicate-purl.cdx.json',
        json_encode($payload, JSON_THROW_ON_ERROR),
    );

    $rows = SoftwareComponent::query()
        ->where('owner_type', SecurityContainer::class)
        ->where('owner_id', $container->id)
        ->where('purl', $duplicate['purl'])
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->license)->toBe('Apache-2.0')
        ->and(SoftwareComponent::query()->where('owner_id', $container->id)->count())->toBe(4);
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

// Same PostgreSQL batch-dedup guard as the duplicate-purl sbom test above: two SARIF
// results sharing (rule_id, file_path, start_line) collapse to one finding row.
it('ingests a sarif report containing two results with the same dedup identity', function () {
    $container = SecurityContainer::factory()->create();

    $payload = json_decode(trivyFixture('vuln-sarif-sample.json'), true, 512, JSON_THROW_ON_ERROR);
    $payload['runs'][0]['results'][] = $payload['runs'][0]['results'][0];

    app(AttachmentService::class)->attachTo(
        $container,
        'vulnerabilities',
        'application/json',
        'vuln-duplicate-result.sarif.json',
        json_encode($payload, JSON_THROW_ON_ERROR),
    );

    expect(
        LocalFinding::query()
            ->where('owner_id', $container->id)
            ->where('rule_id', 'CVE-2024-56201')
            ->count(),
    )->toBe(1);
});

it('populates dedup_hash on every ingested finding', function () {
    $container = SecurityContainer::factory()->create();

    app(AttachmentService::class)->attachTo(
        $container,
        'vulnerabilities',
        'application/json',
        'vuln.sarif.json',
        trivyFixture('vuln-sarif-sample.json'),
    );

    $finding = LocalFinding::query()->where('owner_id', $container->id)->firstOrFail();

    expect($finding->dedup_hash)->toBe(
        LocalFinding::computeDedupHash($finding->rule_id, $finding->file_path, $finding->start_line),
    );
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

it('parses a code-quality-opengrep attachment into local findings', function () {
    $container = SecurityContainer::factory()->create();

    app(AttachmentService::class)->attachTo(
        $container,
        'code-quality-opengrep',
        'application/json',
        'opengrep.sarif',
        staticAnalysisFixture('opengrep-sample.json'),
    );

    $findings = LocalFinding::query()->where('owner_id', $container->id)->get();

    expect($findings)->toHaveCount(2)
        ->and($findings->pluck('kind')->unique()->all())->toBe([LocalFinding::KIND_CODE_QUALITY])
        ->and($findings->firstWhere('rule_id', 'javascript.express.security.audit.xss.direct-response-write.direct-response-write'))->not->toBeNull()
        ->and($findings->firstWhere('rule_id', 'typescript.lang.security.audit.unsafe-child-process.unsafe-child-process'))->not->toBeNull();
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

it('ingests an SBOM attached directly to a SoftwareSystem, stamping owner_type/owner_id', function () {
    $system = SoftwareSystem::factory()->create();

    app(AttachmentService::class)->attachTo(
        $system,
        'sbom',
        'application/json',
        'sbom.cdx.json',
        trivyFixture('cyclonedx-sample.json'),
    );

    $component = SoftwareComponent::query()->where('software_system_id', $system->id)->firstOrFail();

    expect($component->owner_type)->toBe(SoftwareSystem::class)
        ->and($component->owner_id)->toBe($system->id);
});

it('ingests an SBOM attached directly to a SoftwareAsset, stamping owner_type/owner_id', function () {
    $asset = SoftwareAsset::factory()->create();

    app(AttachmentService::class)->attachTo(
        $asset,
        'sbom',
        'application/json',
        'sbom.cdx.json',
        trivyFixture('cyclonedx-sample.json'),
    );

    $component = SoftwareComponent::query()->where('software_asset_id', $asset->id)->firstOrFail();

    expect($component->owner_type)->toBe(SoftwareAsset::class)
        ->and($component->owner_id)->toBe($asset->id)
        ->and($component->software_system_id)->toBeNull();
});

it('re-scanning an SBOM attached to a SoftwareSystem updates the same row instead of duplicating it', function () {
    $system = SoftwareSystem::factory()->create();
    $service = app(AttachmentService::class);

    $service->attachTo($system, 'sbom', 'application/json', 'first.json', trivyFixture('cyclonedx-sample.json'));
    $service->attachTo($system, 'sbom', 'application/json', 'second.json', trivyFixture('cyclonedx-sample.json'));

    expect(SoftwareComponent::query()->where('software_system_id', $system->id)->count())->toBe(4);
});

it('ingests findings attached directly to a SoftwareSystem, stamping owner_type/owner_id', function () {
    $system = SoftwareSystem::factory()->create();

    app(AttachmentService::class)->attachTo(
        $system,
        'vulnerabilities',
        'application/json',
        'vuln.sarif.json',
        trivyFixture('vuln-sarif-sample.json'),
    );

    $finding = LocalFinding::query()->where('software_system_id', $system->id)->firstOrFail();

    expect($finding->owner_type)->toBe(SoftwareSystem::class)
        ->and($finding->owner_id)->toBe($system->id);
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

it('upserts a large SBOM spanning multiple chunks, and sweeps correctly across the chunk boundary', function () {
    $container = SecurityContainer::factory()->create();
    $service = app(AttachmentService::class);

    $purls = array_map(fn (int $i): string => "pkg:nuget/Package{$i}@1.0.0", range(1, 700));

    $service->attachTo($container, 'sbom', 'application/json', 'first.json', minimalCycloneDx($purls));

    expect(SoftwareComponent::query()->where('owner_id', $container->id)->count())->toBe(700)
        ->and(SoftwareComponent::query()->where('owner_id', $container->id)->whereNull('removed_at')->count())->toBe(700)
        ->and(SoftwareComponent::query()->where('owner_id', $container->id)->whereNull('first_seen_at')->exists())->toBeFalse();

    // Re-attach the exact same 700 components: still 700 rows, none newly removed — proves the
    // chunked upsert doesn't create duplicates across the 500-row chunk boundary.
    $service->attachTo($container, 'sbom', 'application/json', 'second.json', minimalCycloneDx($purls));

    expect(SoftwareComponent::query()->where('owner_id', $container->id)->count())->toBe(700)
        ->and(SoftwareComponent::query()->where('owner_id', $container->id)->whereNull('removed_at')->count())->toBe(700);

    // Re-scan with only the second half present — the missing half spans across where the
    // first chunk boundary (500) used to be, proving the sweep's touchedIds are correct across
    // chunks, not just within a single chunk.
    $secondHalf = array_slice($purls, 500);
    $service->attachTo($container, 'sbom', 'application/json', 'third.json', minimalCycloneDx($secondHalf));

    expect(SoftwareComponent::query()->where('owner_id', $container->id)->whereNull('removed_at')->count())->toBe(200)
        ->and(SoftwareComponent::query()->where('owner_id', $container->id)->whereNotNull('removed_at')->count())->toBe(500)
        ->and(SoftwareComponent::query()->where('owner_id', $container->id)->whereIn('purl', $secondHalf)->whereNull('removed_at')->count())->toBe(200);
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

it('upserts a large SARIF spanning multiple chunks, sweeps and correlates correctly across the chunk boundary', function () {
    $container = SecurityContainer::factory()->create();
    $service = app(AttachmentService::class);

    // One finding, deliberately placed past the first 500-row chunk boundary, carries package
    // info matching a pre-existing Dependency SecurityEvent — proving correlateBatch() still
    // runs correctly across the whole set, not just within the first chunk.
    $matchedEvent = SecurityEvent::factory()->forContainer($container)->create([
        'type' => EventType::Dependency,
        'metadata' => ['package' => ['name' => 'Jinja2', 'version' => '3.1.4']],
    ]);

    $findings = array_map(fn (int $i): array => [
        'ruleId' => "CVE-2024-{$i}",
        'filePath' => 'requirements.txt',
        'startLine' => $i,
        'packageName' => $i === 600 ? 'Jinja2' : null,
        'packageVersion' => $i === 600 ? '3.1.4' : null,
    ], range(1, 700));

    $service->attachTo($container, 'vulnerabilities', 'application/json', 'first.json', vulnerabilitySarif($findings));

    expect(LocalFinding::query()->where('owner_id', $container->id)->count())->toBe(700)
        ->and(LocalFinding::query()->where('owner_id', $container->id)->whereNull('dedup_hash')->exists())->toBeFalse();

    $matched = LocalFinding::query()->where('owner_id', $container->id)->where('rule_id', 'CVE-2024-600')->firstOrFail();
    expect($matched->correlated_security_event_id)->toBe($matchedEvent->id)
        ->and($matched->correlation_method)->toBe('package_version');

    // Re-scan with only the second half present — the missing half spans across where the
    // first chunk boundary (500) used to be, proving the sweep's touchedIds are correct across
    // chunks, not just within a single chunk.
    $secondHalf = array_slice($findings, 500);
    $service->attachTo($container, 'vulnerabilities', 'application/json', 'second.json', vulnerabilitySarif($secondHalf));

    expect(LocalFinding::query()->where('owner_id', $container->id)->where('status', EventState::Open)->count())->toBe(200)
        ->and(LocalFinding::query()->where('owner_id', $container->id)->where('status', EventState::Resolved)->count())->toBe(500);
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

/**
 * Like minimalVulnerabilitySarif(), but each finding may also carry a 'packageName'/
 * 'packageVersion' pair — encoded as SARIF Trivy does, via "Key: Value" lines in the message
 * text — so SecurityEventCorrelator has something to match against.
 */
function vulnerabilitySarif(array $findings): string
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
                'message' => ['text' => implode("\n", array_filter([
                    'Severity: MEDIUM',
                    isset($finding['packageName']) ? "Package: {$finding['packageName']}" : null,
                    isset($finding['packageVersion']) ? "Installed Version: {$finding['packageVersion']}" : null,
                ]))],
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
