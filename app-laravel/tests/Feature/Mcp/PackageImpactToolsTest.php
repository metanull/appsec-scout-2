<?php

use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Sbom\FindAdvisoryImpactTool;
use App\Mcp\Tools\Sbom\FindPackageUsageTool;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->reader = mcpUser('Reader');
});

function usageContainer(string $name, ?SoftwareSystem $system = null): SecurityContainer
{
    $system ??= SoftwareSystem::factory()->create(['software_asset_id' => SoftwareAsset::factory()->create()->id]);

    return SecurityContainer::factory()->forSystem($system)->create(['name' => $name]);
}

/** @param array<string, mixed> $attributes */
function usageComponent(SecurityContainer $container, string $ecosystem, string $name, string $version, array $attributes = []): SoftwareComponent
{
    return SoftwareComponent::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $container->software_system_id,
        'software_asset_id' => $container->softwareSystem?->software_asset_id,
        'name' => $name,
        'version' => $version,
        'ecosystem' => $ecosystem,
        'purl' => "pkg:{$ecosystem}/{$name}@{$version}",
        ...$attributes,
    ]);
}

/** @param array<string, mixed> $attributes */
function usageFinding(SecurityContainer $container, string $package, string $version, string $advisory, array $attributes = []): LocalFinding
{
    return LocalFinding::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $container->software_system_id,
        'software_asset_id' => $container->softwareSystem?->software_asset_id,
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => $advisory,
        'title' => "{$package}: {$advisory}",
        'file_path' => 'packages.lock.json',
        'package_name' => $package,
        'package_version' => $version,
        'severity' => 'HIGH',
        ...$attributes,
    ]);
}

/**
 * An AzDO dependency alert on a container, or an alert on a whole system when $container is null.
 *
 * @param  array<string, mixed>  $security
 * @param  array<string, mixed>  $attributes
 */
function usageAlert(?SecurityContainer $container, ?array $package, array $security, array $attributes = []): SecurityEvent
{
    return SecurityEvent::factory()->create([
        'source_id' => $container === null ? 'asoc' : 'azdo',
        'software_system_id' => $container?->software_system_id ?? SoftwareSystem::factory(),
        'container_id' => $container?->id,
        'type' => EventType::Dependency,
        'severity' => EventSeverity::Critical,
        'state' => EventState::Open,
        'rule_id' => 'DEP1001_' . ($security['advisory'] ?? 'none'),
        'metadata' => array_filter(['package' => $package, 'security' => $security]),
        ...$attributes,
    ]);
}

it('groups a package by version, highest first, then by the containers using it', function () {
    $asset = SoftwareAsset::factory()->create(['name' => 'Payroll']);
    $system = SoftwareSystem::factory()->create(['name' => 'HR', 'software_asset_id' => $asset->id]);
    $web = usageContainer('hr-web', $system);
    $api = usageContainer('hr-api', $system);
    $other = usageContainer('billing');
    usageComponent($web, 'maven', 'log4j-core', '2.9.0');
    usageComponent($api, 'maven', 'log4j-core', '2.17.1');
    usageComponent($other, 'maven', 'log4j-core', '2.17.1');
    usageComponent($web, 'npm', 'left-pad', '1.3.0');

    AppSecScoutServer::actingAs($this->reader)->tool(FindPackageUsageTool::class, ['package' => 'LOG4J'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('total', 1)
            ->where('items.0.ecosystem', 'maven')
            ->where('items.0.name', 'log4j-core')
            ->where('items.0.containers_count', 3)
            ->where('items.0.max_severity', null)
            ->where('items.0.versions.0.version', '2.17.1')
            ->where('items.0.versions.0.containers_count', 2)
            ->where('items.0.versions.0.systems_count', 2)
            ->where('items.0.versions.1.version', '2.9.0')
            ->where('items.0.versions.1.containers', [[
                'owner' => ['type' => 'container', 'id' => $web->id, 'name' => 'hr-web'],
                'system' => ['id' => $system->id, 'name' => 'HR'],
                'asset' => ['id' => $asset->id, 'name' => 'Payroll'],
                'removed_at' => null,
                'advisories' => [],
            ]])
            ->etc());
});

it('lists each version\'s advisories from local findings and from alerts on the same container, package and version', function () {
    $system = SoftwareSystem::factory()->create();
    $container = usageContainer('dm-request', $system);
    $elsewhere = usageContainer('elsewhere', $system);
    usageComponent($container, 'nuget', 'System.Security.Cryptography.Pkcs', '8.0.1');
    usageComponent($elsewhere, 'nuget', 'System.Security.Cryptography.Pkcs', '8.0.1');
    usageFinding($container, 'System.Security.Cryptography.Pkcs', '8.0.1', 'CVE-2026-70162');
    usageAlert($container, ['name' => 'system.security.cryptography.pkcs', 'version' => '8.0.1', 'ecosystem' => 'nuget'], ['cve' => 'CVE-2026-70162', 'advisory' => 'GHSA-2fmv-rv2c-59x7']);
    usageAlert($container, ['name' => 'system.security.cryptography.pkcs', 'version' => '9.0.0'], ['advisory' => 'GHSA-only-other-version'], ['severity' => EventSeverity::Low]);
    usageAlert($container, ['name' => 'system.security.cryptography.pkcs', 'version' => '8.0.1'], ['advisory' => 'GHSA-xxxx-yyyy-zzzz'], ['severity' => EventSeverity::Medium]);
    usageAlert(usageContainer('unrelated'), ['name' => 'system.security.cryptography.pkcs', 'version' => '8.0.1'], ['cve' => 'CVE-2026-70162']);

    AppSecScoutServer::actingAs($this->reader)->tool(FindPackageUsageTool::class, ['package' => 'cryptography.pkcs'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('items.0.max_severity', 'critical')
            ->where('items.0.versions.0.advisories', [
                ['id' => 'CVE-2026-70162', 'severity' => 'critical', 'findings_count' => 1, 'alerts_count' => 1],
                ['id' => 'GHSA-xxxx-yyyy-zzzz', 'severity' => 'medium', 'findings_count' => 0, 'alerts_count' => 1],
            ])
            ->where('items.0.versions.0.containers.0.owner.name', 'dm-request')
            ->where('items.0.versions.0.containers.0.advisories', ['CVE-2026-70162', 'GHSA-xxxx-yyyy-zzzz'])
            ->where('items.0.versions.0.containers.1.owner.name', 'elsewhere')
            ->where('items.0.versions.0.containers.1.advisories', [])
            ->etc());
});

it('counts only the findings and alerts passing the vulnerability filters', function () {
    $container = usageContainer('app');
    usageComponent($container, 'npm', 'lodash', '4.17.19');
    usageFinding($container, 'lodash', '4.17.19', 'CVE-2020-8203', ['status' => EventState::Dismissed]);
    usageAlert($container, ['name' => 'lodash', 'version' => '4.17.19'], ['cve' => 'CVE-2021-23337'], ['state' => EventState::Resolved]);

    AppSecScoutServer::actingAs($this->reader)->tool(FindPackageUsageTool::class, ['package' => 'lodash'])
        ->assertStructuredContent(fn ($json) => $json->where('items.0.versions.0.advisories', [])->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(FindPackageUsageTool::class, ['package' => 'lodash', 'vulnerability_status' => []])
        ->assertStructuredContent(fn ($json) => $json
            ->where('items.0.versions.0.advisories.0.id', 'CVE-2021-23337')
            ->where('items.0.versions.0.advisories.1.id', 'CVE-2020-8203')
            ->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(FindPackageUsageTool::class, ['package' => 'lodash', 'vulnerability_status' => [], 'vulnerability_severity' => ['high']])
        ->assertStructuredContent(fn ($json) => $json
            ->where('items.0.versions.0.advisories', [['id' => 'CVE-2020-8203', 'severity' => 'high', 'findings_count' => 1, 'alerts_count' => 0]])
            ->etc());
});

it('paginates by package and honours the ecosystem, scope and removed filters', function () {
    $container = usageContainer('web');
    usageComponent($container, 'npm', 'react', '18.2.0');
    usageComponent($container, 'npm', 'reactx', '1.0.0', ['removed_at' => now()]);
    usageComponent($container, 'nuget', 'reactive', '6.0.1');
    usageComponent(usageContainer('other'), 'npm', 'react', '17.0.2');

    $packages = function (array $arguments): array {
        $packages = [];

        AppSecScoutServer::actingAs($this->reader)->tool(FindPackageUsageTool::class, ['package' => 'react', ...$arguments])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use (&$packages): void {
                $packages = array_map(fn (array $item): string => "{$item['ecosystem']}:{$item['name']}", $json->toArray()['items']);
                $json->etc();
            });

        return $packages;
    };

    expect($packages([]))->toBe(['npm:react', 'nuget:reactive'])
        ->and($packages(['include_removed' => true]))->toBe(['npm:react', 'nuget:reactive', 'npm:reactx'])
        ->and($packages(['ecosystem' => 'npm']))->toBe(['npm:react'])
        ->and($packages(['per_page' => 1, 'page' => 2]))->toBe(['nuget:reactive']);

    AppSecScoutServer::actingAs($this->reader)->tool(FindPackageUsageTool::class, ['package' => 'react', 'ecosystem' => 'npm', 'container_id' => $container->id])
        ->assertStructuredContent(fn ($json) => $json
            ->where('total', 1)
            ->where('items.0.versions.0.version', '18.2.0')
            ->has('items.0.versions', 1)
            ->etc());
});

it('maps an advisory to the packages, containers and systems it affects', function () {
    $asset = SoftwareAsset::factory()->create(['name' => 'Payroll']);
    $system = SoftwareSystem::factory()->create(['name' => 'HR', 'software_asset_id' => $asset->id]);
    $container = usageContainer('hr-web', $system);
    usageComponent($container, 'nuget', 'Newtonsoft.Json', '12.0.1');
    $finding = usageFinding($container, 'Newtonsoft.Json', '12.0.1', 'CVE-2024-21907');
    $alert = usageAlert($container, ['name' => 'newtonsoft.json', 'version' => '12.0.1', 'ecosystem' => 'nuget'], ['cve' => 'CVE-2024-21907', 'advisory' => 'GHSA-5crp-9r3c-p9vr']);
    $finding->update(['correlated_security_event_id' => $alert->id]);
    $systemWide = usageAlert(null, null, ['cve' => 'CVE-2024-21907'], ['severity' => EventSeverity::High, 'title' => 'Newtonsoft.Json 12.0.1']);
    usageFinding($container, 'Newtonsoft.Json', '12.0.1', 'CVE-2024-219070');
    usageAlert($container, ['name' => 'newtonsoft.json', 'version' => '12.0.1'], ['cve' => 'CVE-2024-219070']);

    AppSecScoutServer::actingAs($this->reader)->tool(FindAdvisoryImpactTool::class, ['advisory' => 'cve-2024-21907'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('advisory', 'cve-2024-21907')
            ->where('summary', [
                'packages_count' => 2,
                'containers_count' => 1,
                'systems_count' => 2,
                'assets_count' => 1,
                'findings_count' => 1,
                'alerts_count' => 2,
                'max_severity' => 'critical',
            ])
            ->where('packages.0.package', ['name' => 'Newtonsoft.Json', 'version' => '12.0.1', 'ecosystem' => 'nuget'])
            ->where('packages.0.max_severity', 'critical')
            ->where('packages.0.owners', [[
                'owner' => ['type' => 'container', 'id' => $container->id, 'name' => 'hr-web'],
                'system' => ['id' => $system->id, 'name' => 'HR'],
                'asset' => ['id' => $asset->id, 'name' => 'Payroll'],
                'findings' => [[
                    'id' => $finding->id,
                    'status' => 'open',
                    'severity' => 'high',
                    'file_path' => 'packages.lock.json',
                    'correlated_alert_id' => $alert->id,
                ]],
                'alerts' => [[
                    'id' => $alert->id,
                    'source_id' => 'azdo',
                    'type' => 'dependency',
                    'state' => 'open',
                    'severity' => 'critical',
                    'title' => $alert->title,
                ]],
            ]])
            ->where('packages.1.package', null)
            ->where('packages.1.owners.0.owner', ['type' => 'system', 'id' => $systemWide->software_system_id, 'name' => $systemWide->softwareSystem->name])
            ->where('packages.1.owners.0.alerts.0.id', $systemWide->id)
            ->etc());
});

it('narrows an advisory\'s impact by status and scope, and rejects an id that is not one', function () {
    $kept = usageContainer('kept');
    $other = usageContainer('other');
    usageFinding($kept, 'lodash', '4.17.19', 'GHSA-p6mc-m468-83gw');
    usageFinding($other, 'lodash', '4.17.19', 'GHSA-p6mc-m468-83gw');
    usageFinding($kept, 'lodash', '4.17.20', 'GHSA-p6mc-m468-83gw', ['status' => EventState::Resolved]);

    AppSecScoutServer::actingAs($this->reader)->tool(FindAdvisoryImpactTool::class, ['advisory' => 'GHSA-P6MC-M468-83GW', 'container_id' => $kept->id])
        ->assertStructuredContent(fn ($json) => $json
            ->where('summary.findings_count', 1)
            ->where('packages.0.package.version', '4.17.19')
            ->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(FindAdvisoryImpactTool::class, ['advisory' => 'GHSA-p6mc-m468-83gw', 'vulnerability_status' => []])
        ->assertStructuredContent(fn ($json) => $json->where('summary.findings_count', 3)->where('summary.containers_count', 2)->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(FindAdvisoryImpactTool::class, ['advisory' => 'CVE-2021-44228'])
        ->assertStructuredContent(fn ($json) => $json->where('title', null)->where('packages', [])->where('summary.max_severity', null)->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(FindAdvisoryImpactTool::class, ['advisory' => 'CVE-2021-%'])
        ->assertHasErrors(['The advisory must be an id such as CVE-2021-44228 or GHSA-jfh8-c2jp-5v3q.']);
});

it('refuses the package and advisory tools without alerts.view', function (string $tool, array $arguments) {
    AppSecScoutServer::actingAs(mcpUser(null))->tool($tool, $arguments)
        ->assertHasErrors(['Permission denied: this tool requires the alerts.view permission.']);
})->with([
    'package usage' => [FindPackageUsageTool::class, ['package' => 'lodash']],
    'advisory impact' => [FindAdvisoryImpactTool::class, ['advisory' => 'CVE-2021-44228']],
]);
