<?php

use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Findings\GetAlertTool;
use App\Mcp\Tools\Findings\GetLocalFindingTool;
use App\Mcp\Tools\Findings\ListAlertsTool;
use App\Mcp\Tools\Findings\ListLocalFindingsTool;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\EventComment;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\WorkItemLink;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->reader = mcpUser('Reader');
});

/** @param array<string, mixed> $attributes */
function containerFinding(SecurityContainer $container, array $attributes = []): LocalFinding
{
    static $sequence = 0;
    $sequence++;

    return LocalFinding::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $container->software_system_id,
        'software_asset_id' => $container->softwareSystem?->software_asset_id,
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2026-' . $sequence,
        'title' => 'Finding ' . $sequence,
        'file_path' => 'package-lock.json',
        'severity' => 'HIGH',
        'last_seen_at' => now(),
        ...$attributes,
    ]);
}

it('lists open alerts by default, most severe first', function () {
    $system = SoftwareSystem::factory()->create();
    $high = SecurityEvent::factory()->forSystem($system)->create(['severity' => EventSeverity::High, 'title' => 'High one']);
    $critical = SecurityEvent::factory()->forSystem($system)->create(['severity' => EventSeverity::Critical, 'title' => 'Critical one']);
    SecurityEvent::factory()->forSystem($system)->create(['state' => EventState::Resolved]);

    AppSecScoutServer::actingAs($this->reader)->tool(ListAlertsTool::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('total', 2)
            ->where('items.0.id', $critical->id)
            ->where('items.1.id', $high->id)
            ->where('items.0.system.id', $system->id)
            ->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(ListAlertsTool::class, ['state' => []])
        ->assertStructuredContent(fn ($json) => $json->where('total', 3)->etc());
});

it('filters alerts by severity, type, source, work item and search', function () {
    $system = SoftwareSystem::factory()->create();
    $secret = SecurityEvent::factory()->forSystem($system)->secret()->create(['source_id' => 'azdo', 'title' => 'Leaked PAT']);
    $linked = SecurityEvent::factory()->forSystem($system)->vulnerability()->create(['source_id' => 'asoc']);
    WorkItemLink::query()->create(['event_id' => $linked->id, 'tracker_id' => 'jira', 'work_item_id' => 'SEC-1', 'created_at' => now()]);

    $ids = fn (array $arguments): array => collect(mcpItemIds(AppSecScoutServer::actingAs($this->reader)->tool(ListAlertsTool::class, $arguments)))->all();

    expect($ids(['severity' => ['critical']]))->toBe([$secret->id])
        ->and($ids(['type' => ['vulnerability']]))->toBe([$linked->id])
        ->and($ids(['source_id' => ['azdo']]))->toBe([$secret->id])
        ->and($ids(['has_work_item' => true]))->toBe([$linked->id])
        ->and($ids(['search' => 'Leaked']))->toBe([$secret->id]);
});

it('scopes alerts to an asset, a system or a container', function () {
    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $container = SecurityContainer::factory()->forSystem($system)->create();
    $inContainer = SecurityEvent::factory()->forContainer($container)->create();
    $onSystem = SecurityEvent::factory()->forSystem($system)->create();
    SecurityEvent::factory()->create();

    $ids = fn (array $arguments): array => collect(mcpItemIds(AppSecScoutServer::actingAs($this->reader)->tool(ListAlertsTool::class, $arguments)))->sort()->values()->all();

    expect($ids(['asset_id' => $asset->id]))->toBe(collect([$inContainer->id, $onSystem->id])->sort()->values()->all())
        ->and($ids(['system_id' => $system->id]))->toBe(collect([$inContainer->id, $onSystem->id])->sort()->values()->all())
        ->and($ids(['container_id' => $container->id]))->toBe([$inContainer->id]);
});

it('returns one alert in full with comments, work items and correlated findings', function () {
    $container = SecurityContainer::factory()->create();
    $event = SecurityEvent::factory()->forContainer($container)->vulnerability()->dirty()->create([
        'remediation' => 'Use parameterized queries.',
        'source_data' => '{"raw":true}',
    ]);
    EventComment::factory()->create(['event_id' => $event->id, 'body' => 'Looks real.', 'author_user_id' => $this->reader->id]);
    WorkItemLink::query()->create(['event_id' => $event->id, 'tracker_id' => 'jira', 'work_item_id' => 'SEC-7', 'work_item_url' => 'https://jira.example.com/SEC-7', 'created_at' => now()]);
    $correlated = containerFinding($container, ['correlated_security_event_id' => $event->id]);

    AppSecScoutServer::actingAs($this->reader)->tool(GetAlertTool::class, ['alert_id' => $event->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('id', $event->id)
            ->where('remediation', 'Use parameterized queries.')
            ->where('location.start_line', 42)
            ->where('pending.state', 'resolved')
            ->where('pending.is_dirty', true)
            ->where('comments.0.body', 'Looks real.')
            ->where('comments.0.author', $this->reader->name)
            ->where('work_items.0.work_item_id', 'SEC-7')
            ->where('work_items_count', 1)
            ->where('correlated_local_finding_ids', [$correlated->id])
            ->missing('source_data')
            ->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(GetAlertTool::class, ['alert_id' => $event->id, 'include_raw' => true])
        ->assertStructuredContent(fn ($json) => $json->where('source_data', '{"raw":true}')->etc());
});

it('lists open local findings by effective severity, including unknown ones', function () {
    $container = SecurityContainer::factory()->create();
    $high = containerFinding($container, ['severity' => 'HIGH']);
    $overridden = containerFinding($container, ['severity' => 'LOW', 'overridden_severity' => EventSeverity::Critical]);
    $unknown = containerFinding($container, ['severity' => 'UNKNOWN']);
    containerFinding($container, ['status' => EventState::Dismissed]);

    AppSecScoutServer::actingAs($this->reader)->tool(ListLocalFindingsTool::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('total', 3)
            ->where('items.0.id', $overridden->id)
            ->where('items.0.severity', 'Critical')
            ->where('items.1.id', $high->id)
            ->where('items.2.id', $unknown->id)
            ->where('items.0.owner', ['type' => 'container', 'id' => $container->id, 'name' => $container->name])
            ->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(ListLocalFindingsTool::class, ['severity' => ['unknown']])
        ->assertStructuredContent(fn ($json) => $json->where('total', 1)->where('items.0.id', $unknown->id)->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(ListLocalFindingsTool::class, ['status' => []])
        ->assertStructuredContent(fn ($json) => $json->where('total', 4)->etc());
});

it('filters local findings by kind, correlation and scope', function () {
    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $container = SecurityContainer::factory()->forSystem($system)->create();
    $secret = containerFinding($container, ['kind' => LocalFinding::KIND_SECRET]);
    $correlated = containerFinding($container, ['correlated_security_event_id' => SecurityEvent::factory()->forContainer($container)->create()->id]);
    containerFinding(SecurityContainer::factory()->create());

    $ids = fn (array $arguments): array => collect(mcpItemIds(AppSecScoutServer::actingAs($this->reader)->tool(ListLocalFindingsTool::class, $arguments)))->sort()->values()->all();

    expect($ids(['kind' => ['secret']]))->toContain($secret->id)->not->toContain($correlated->id)
        ->and($ids(['is_correlated' => true]))->toBe([$correlated->id])
        ->and($ids(['asset_id' => $asset->id]))->toBe(collect([$secret->id, $correlated->id])->sort()->values()->all())
        ->and($ids(['container_id' => $container->id]))->toBe(collect([$secret->id, $correlated->id])->sort()->values()->all());
});

it('returns one local finding in full', function () {
    $container = SecurityContainer::factory()->create();
    $finding = containerFinding($container, [
        'description' => 'Prototype pollution in lodash.',
        'package_name' => 'lodash',
        'package_version' => '4.17.19',
        'overridden_severity' => EventSeverity::Medium,
        'metadata' => ['message' => 'Package: lodash'],
    ]);
    $finding->comments()->create(['body' => 'Not reachable.', 'author_user_id' => $this->reader->id, 'created_at' => now()]);

    AppSecScoutServer::actingAs($this->reader)->tool(GetLocalFindingTool::class, ['finding_id' => $finding->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('id', $finding->id)
            ->where('description', 'Prototype pollution in lodash.')
            ->where('reported_severity', 'HIGH')
            ->where('overridden_severity', 'medium')
            ->where('severity', 'Medium')
            ->where('package_name', 'lodash')
            ->where('metadata', ['message' => 'Package: lodash'])
            ->where('comments.0.body', 'Not reachable.')
            ->where('attachment', null)
            ->etc());
});

it('rejects unknown ids and invalid filter values', function () {
    AppSecScoutServer::actingAs($this->reader)->tool(GetAlertTool::class, ['alert_id' => 999999])
        ->assertHasErrors(['No alert with id 999999.']);
    AppSecScoutServer::actingAs($this->reader)->tool(GetLocalFindingTool::class, ['finding_id' => 999999])
        ->assertHasErrors(['No local finding with id 999999.']);
    AppSecScoutServer::actingAs($this->reader)->tool(ListAlertsTool::class, ['state' => ['nope']])
        ->assertHasErrors();
    AppSecScoutServer::actingAs($this->reader)->tool(ListLocalFindingsTool::class, ['kind' => ['nope']])
        ->assertHasErrors();
});

it('refuses every finding tool without alerts.view', function (string $tool, array $arguments) {
    AppSecScoutServer::actingAs(mcpUser(null))->tool($tool, $arguments)
        ->assertHasErrors(['Permission denied: this tool requires the alerts.view permission.']);
})->with([
    'list alerts' => [ListAlertsTool::class, []],
    'get alert' => [GetAlertTool::class, ['alert_id' => 1]],
    'list local findings' => [ListLocalFindingsTool::class, []],
    'get local finding' => [GetLocalFindingTool::class, ['finding_id' => 1]],
]);
