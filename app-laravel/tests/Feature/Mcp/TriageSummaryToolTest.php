<?php

use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Findings\GetTriageSummaryTool;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
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
function summaryFinding(SecurityContainer $container, array $attributes = []): LocalFinding
{
    static $sequence = 0;
    $sequence++;

    return LocalFinding::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $container->software_system_id,
        'software_asset_id' => $container->softwareSystem?->software_asset_id,
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'RULE-' . $sequence,
        'title' => 'Finding ' . $sequence,
        'file_path' => 'src/file' . $sequence . '.cs',
        'severity' => 'HIGH',
        ...$attributes,
    ]);
}

it('summarizes alerts and local findings of a system across sources and kinds', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    SecurityEvent::factory()->forSystem($system)->create(['source_id' => 'azdo', 'severity' => EventSeverity::Critical, 'type' => EventType::Secret]);
    $tracked = SecurityEvent::factory()->forSystem($system)->create(['source_id' => 'asoc', 'severity' => EventSeverity::High, 'type' => EventType::Vulnerability, 'state' => EventState::Acknowledged]);
    SecurityEvent::factory()->forSystem($system)->dirty()->create(['source_id' => 'asoc', 'severity' => EventSeverity::High, 'type' => EventType::Vulnerability]);
    SecurityEvent::factory()->forSystem($system)->create(['state' => EventState::Resolved]);
    SecurityEvent::factory()->create();
    WorkItemLink::query()->create(['event_id' => $tracked->id, 'tracker_id' => 'jira', 'work_item_id' => 'SEC-1', 'created_at' => now()]);

    summaryFinding($container, ['severity' => 'HIGH']);
    summaryFinding($container, ['kind' => LocalFinding::KIND_SECRET, 'severity' => 'LOW', 'overridden_severity' => EventSeverity::Critical]);
    summaryFinding($container, ['kind' => LocalFinding::KIND_CODE_QUALITY, 'severity' => 'UNKNOWN', 'correlated_security_event_id' => $tracked->id]);
    summaryFinding($container, ['status' => EventState::Dismissed]);

    AppSecScoutServer::actingAs($this->reader)->tool(GetTriageSummaryTool::class, ['system_id' => $system->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('scope', ['type' => 'system', 'id' => $system->id, 'name' => $system->name])
            ->where('alerts.total', 3)
            ->where('alerts.by_severity', ['critical' => 1, 'high' => 2, 'medium' => 0, 'low' => 0, 'informational' => 0])
            ->where('alerts.by_type.secret', 1)
            ->where('alerts.by_type.vulnerability', 2)
            ->where('alerts.by_type.iac', 0)
            ->where('alerts.by_source', ['asoc' => 2, 'azdo' => 1])
            ->where('alerts.pending_sync', 1)
            ->where('alerts.without_work_item', 2)
            ->where('local_findings.total', 3)
            ->where('local_findings.by_severity', ['critical' => 1, 'high' => 1, 'medium' => 0, 'low' => 0, 'informational' => 0, 'unknown' => 1])
            ->where('local_findings.by_kind', ['vulnerability' => 1, 'secret' => 1, 'code_quality' => 1])
            ->where('local_findings.correlated_to_alert', 1)
            ->where('local_findings.without_work_item', 3)
            ->missing('systems')
            ->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(GetTriageSummaryTool::class, ['system_id' => $system->id, 'state' => []])
        ->assertStructuredContent(fn ($json) => $json
            ->where('alerts.total', 4)
            ->where('local_findings.total', 4)
            ->etc());
});

it('ranks an asset\'s systems by critical and high counts', function () {
    $asset = SoftwareAsset::factory()->create();
    $quiet = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id, 'name' => 'Quiet']);
    $noisy = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id, 'name' => 'Noisy']);
    $container = SecurityContainer::factory()->forSystem($noisy)->create();

    SecurityEvent::factory()->forSystem($quiet)->create(['severity' => EventSeverity::Low]);
    SecurityEvent::factory()->forSystem($noisy)->create(['severity' => EventSeverity::Critical]);
    summaryFinding($container, ['overridden_severity' => EventSeverity::Critical]);
    summaryFinding($container, ['severity' => 'HIGH']);

    AppSecScoutServer::actingAs($this->reader)->tool(GetTriageSummaryTool::class, ['asset_id' => $asset->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('scope.type', 'asset')
            ->where('alerts.total', 2)
            ->where('local_findings.total', 2)
            ->where('systems', [
                ['id' => $noisy->id, 'name' => 'Noisy', 'source_id' => $noisy->source_id, 'alerts_total' => 1, 'local_findings_total' => 2, 'critical' => 2, 'high' => 1],
                ['id' => $quiet->id, 'name' => 'Quiet', 'source_id' => $quiet->source_id, 'alerts_total' => 1, 'local_findings_total' => 0, 'critical' => 0, 'high' => 0],
            ])
            ->etc());
});

it('requires exactly one scope', function () {
    AppSecScoutServer::actingAs($this->reader)->tool(GetTriageSummaryTool::class)
        ->assertHasErrors(['Provide exactly one of asset_id, system_id or container_id.']);

    AppSecScoutServer::actingAs($this->reader)->tool(GetTriageSummaryTool::class, [
        'system_id' => SoftwareSystem::factory()->create()->id,
        'asset_id' => SoftwareAsset::factory()->create()->id,
    ])->assertHasErrors(['Provide exactly one of asset_id, system_id or container_id.']);
});

it('refuses the summary without alerts.view', function () {
    AppSecScoutServer::actingAs(mcpUser(null))->tool(GetTriageSummaryTool::class, ['system_id' => SoftwareSystem::factory()->create()->id])
        ->assertHasErrors(['Permission denied: this tool requires the alerts.view permission.']);
});
