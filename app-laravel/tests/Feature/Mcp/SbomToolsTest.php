<?php

use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Sbom\GetSbomTool;
use App\Mcp\Tools\Sbom\ListSbomComponentsTool;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->reader = mcpUser('Reader');
});

/** @param array<string, mixed> $attributes */
function sbomComponent(SecurityContainer $container, string $name, string $version, array $attributes = []): SoftwareComponent
{
    return SoftwareComponent::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $container->software_system_id,
        'software_asset_id' => $container->softwareSystem?->software_asset_id,
        'name' => $name,
        'version' => $version,
        'ecosystem' => 'npm',
        'purl' => "pkg:npm/{$name}@{$version}",
        ...$attributes,
    ]);
}

/** @param array<string, mixed> $attributes */
function packageVulnerability(SecurityContainer $container, string $package, string $version, string $cve, array $attributes = []): LocalFinding
{
    return LocalFinding::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $container->software_system_id,
        'software_asset_id' => $container->softwareSystem?->software_asset_id,
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => $cve,
        'title' => "{$cve} in {$package}",
        'file_path' => 'package-lock.json',
        'package_name' => $package,
        'package_version' => $version,
        'severity' => 'HIGH',
        ...$attributes,
    ]);
}

it('counts only vulnerabilities on the same owner, package name and version', function () {
    $container = SecurityContainer::factory()->create();
    $other = SecurityContainer::factory()->create();
    $lodash = sbomComponent($container, 'lodash', '4.17.19');
    packageVulnerability($container, 'lodash', '4.17.19', 'CVE-2020-8203');
    packageVulnerability($container, 'lodash', '4.17.20', 'CVE-2021-23337');
    packageVulnerability($other, 'lodash', '4.17.19', 'CVE-2020-8203');

    AppSecScoutServer::actingAs($this->reader)->tool(ListSbomComponentsTool::class, ['container_id' => $container->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('total', 1)
            ->where('items.0.id', $lodash->id)
            ->where('items.0.vulnerabilities', [
                'count' => 1,
                'max_severity' => 'high',
                'by_severity' => ['critical' => 0, 'high' => 1, 'medium' => 0, 'low' => 0, 'informational' => 0, 'unknown' => 0],
            ])
            ->etc());
});

it('filters on vulnerability status and severity and orders the most vulnerable first', function () {
    $container = SecurityContainer::factory()->create();
    $clean = sbomComponent($container, 'axios', '1.7.0');
    $high = sbomComponent($container, 'lodash', '4.17.19');
    $critical = sbomComponent($container, 'minimist', '1.2.5');
    $dismissed = sbomComponent($container, 'qs', '6.5.2');
    $unknown = sbomComponent($container, 'tar', '6.1.0');
    packageVulnerability($container, 'lodash', '4.17.19', 'CVE-1');
    packageVulnerability($container, 'minimist', '1.2.5', 'CVE-2', ['overridden_severity' => EventSeverity::Critical]);
    packageVulnerability($container, 'qs', '6.5.2', 'CVE-3', ['severity' => 'CRITICAL', 'status' => EventState::Dismissed]);
    packageVulnerability($container, 'tar', '6.1.0', 'CVE-4', ['severity' => 'UNKNOWN']);

    expect(mcpItemIds(AppSecScoutServer::actingAs($this->reader)->tool(ListSbomComponentsTool::class, ['container_id' => $container->id])))
        ->toBe([$critical->id, $high->id, $clean->id, $dismissed->id, $unknown->id]);

    expect(mcpItemIds(AppSecScoutServer::actingAs($this->reader)->tool(ListSbomComponentsTool::class, ['container_id' => $container->id, 'vulnerable_only' => true])))
        ->toBe([$critical->id, $high->id, $unknown->id]);

    expect(mcpItemIds(AppSecScoutServer::actingAs($this->reader)->tool(ListSbomComponentsTool::class, ['container_id' => $container->id, 'vulnerable_only' => true, 'vulnerability_status' => []])))
        ->toBe([$critical->id, $dismissed->id, $high->id, $unknown->id]);

    expect(mcpItemIds(AppSecScoutServer::actingAs($this->reader)->tool(ListSbomComponentsTool::class, ['container_id' => $container->id, 'vulnerable_only' => true, 'vulnerability_severity' => ['unknown']])))
        ->toBe([$unknown->id]);
});

it('scopes components to an asset or a system and hides removed ones', function () {
    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $container = SecurityContainer::factory()->forSystem($system)->create();
    $kept = sbomComponent($container, 'lodash', '4.17.21');
    sbomComponent($container, 'left-pad', '1.3.0', ['removed_at' => now()]);
    sbomComponent(SecurityContainer::factory()->create(), 'elsewhere', '1.0.0');

    expect(mcpItemIds(AppSecScoutServer::actingAs($this->reader)->tool(ListSbomComponentsTool::class, ['asset_id' => $asset->id])))->toBe([$kept->id])
        ->and(mcpItemIds(AppSecScoutServer::actingAs($this->reader)->tool(ListSbomComponentsTool::class, ['system_id' => $system->id])))->toBe([$kept->id])
        ->and(mcpItemIds(AppSecScoutServer::actingAs($this->reader)->tool(ListSbomComponentsTool::class, ['system_id' => $system->id, 'include_removed' => true])))->toHaveCount(2);
});

it('returns a container\'s SBOM catalog with each component\'s vulnerabilities', function () {
    $container = SecurityContainer::factory()->create();
    $attachment = fn (string $name, string $at) => $container->attachments()->create([
        'kind' => 'sbom',
        'mime' => 'application/json',
        'name' => $name,
        'payload' => '{"bomFormat":"CycloneDX","components":[]}',
        'size_bytes' => 17,
        'created_at' => $at,
    ]);
    $attachment('old.cdx.json', '2026-09-01 10:00:00');
    $latest = $attachment('new.cdx.json', '2026-10-01 10:00:00');
    sbomComponent($container, 'axios', '1.7.0');
    $lodash = sbomComponent($container, 'lodash', '4.17.19');
    $finding = packageVulnerability($container, 'lodash', '4.17.19', 'CVE-2020-8203');

    AppSecScoutServer::actingAs($this->reader)->tool(GetSbomTool::class, ['container_id' => $container->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('container.id', $container->id)
            ->where('sbom.attachment_id', $latest->id)
            ->where('sbom.name', 'new.cdx.json')
            ->where('total', 2)
            ->where('items.0.id', $lodash->id)
            ->where('items.0.vulnerabilities', [[
                'finding_id' => $finding->id,
                'rule_id' => 'CVE-2020-8203',
                'title' => 'CVE-2020-8203 in lodash',
                'severity' => 'high',
                'status' => 'open',
            ]])
            ->where('items.1.vulnerabilities', [])
            ->etc());

    expect(mcpItemIds(AppSecScoutServer::actingAs($this->reader)->tool(GetSbomTool::class, ['container_id' => $container->id, 'vulnerable_only' => true])))
        ->toBe([$lodash->id]);
});

it('reports a container without an SBOM and rejects an unknown one', function () {
    $container = SecurityContainer::factory()->create();

    AppSecScoutServer::actingAs($this->reader)->tool(GetSbomTool::class, ['container_id' => $container->id])
        ->assertStructuredContent(fn ($json) => $json->where('sbom', null)->where('total', 0)->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(GetSbomTool::class, ['container_id' => 999999])
        ->assertHasErrors(['No security container with id 999999.']);
});

it('refuses the SBOM tools without alerts.view', function (string $tool, array $arguments) {
    AppSecScoutServer::actingAs(mcpUser(null))->tool($tool, $arguments)
        ->assertHasErrors(['Permission denied: this tool requires the alerts.view permission.']);
})->with([
    'list components' => [ListSbomComponentsTool::class, []],
    'get sbom' => [GetSbomTool::class, ['container_id' => 1]],
]);
