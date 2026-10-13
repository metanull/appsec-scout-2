<?php

use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Findings\FindSimilarFindingsTool;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\EventComment;
use App\Models\LocalFinding;
use App\Models\LocalFindingComment;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->reader = mcpUser('Reader');
});

function similarFinding(SoftwareSystem $system, array $attributes = []): LocalFinding
{
    $container = SecurityContainer::factory()->forSystem($system)->create();

    return LocalFinding::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $system->id,
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-1234',
        'title' => 'openssl vulnerability',
        'severity' => 'high',
        'file_path' => 'package-lock.json',
        'package_name' => 'openssl',
        'package_version' => '1.1.1',
        'status' => EventState::Open,
        ...$attributes,
    ]);
}

it('finds alerts and local findings with the same rule across systems, with their latest comment', function () {
    [$a, $b, $c] = SoftwareSystem::factory()->count(3)->create();
    $reference = SecurityEvent::factory()->create(['software_system_id' => $a->id, 'rule_id' => 'CVE-2024-1234', 'state' => EventState::Open]);
    $dismissed = SecurityEvent::factory()->create(['software_system_id' => $b->id, 'rule_id' => 'CVE-2024-1234', 'state' => EventState::Dismissed]);
    SecurityEvent::factory()->create(['rule_id' => 'CVE-2099-0001']);
    EventComment::query()->create(['event_id' => $dismissed->id, 'body' => 'Older note.', 'created_at' => now()->subDay()]);
    EventComment::query()->create(['event_id' => $dismissed->id, 'body' => 'Not reachable: test-only dependency.', 'created_at' => now()]);
    $finding = similarFinding($c, ['overridden_severity' => EventSeverity::Low]);

    AppSecScoutServer::actingAs($this->reader)->tool(FindSimilarFindingsTool::class, ['alert_id' => $reference->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('reference', ['kind' => 'alert', 'id' => $reference->id, 'rule_id' => 'CVE-2024-1234', 'package_name' => null, 'package_version' => null])
            ->where('summary', ['alerts_by_state' => ['dismissed' => 1], 'local_findings_by_status' => ['open' => 1], 'systems' => 2])
            ->where('alerts.0.id', $dismissed->id)
            ->where('alerts.0.state', 'dismissed')
            ->where('alerts.0.latest_comment.body', 'Not reachable: test-only dependency.')
            ->has('alerts', 1)
            ->where('local_findings.0.id', $finding->id)
            ->where('local_findings.0.severity', 'low')
            ->where('local_findings.0.owner.type', 'container')
            ->where('local_findings.0.package', 'openssl 1.1.1')
            ->where('local_findings.0.latest_comment', null)
            ->etc());
});

it('starts from a local finding, matches by package and can hide closed findings', function () {
    $system = SoftwareSystem::factory()->create();
    $reference = similarFinding($system);
    $otherVersion = similarFinding(SoftwareSystem::factory()->create(), ['rule_id' => 'CVE-2023-9999', 'package_version' => '3.0.0']);
    $resolved = similarFinding(SoftwareSystem::factory()->create(), ['status' => EventState::Resolved]);
    LocalFindingComment::query()->create(['local_finding_id' => $resolved->id, 'body' => 'Upgraded to 3.0.']);
    similarFinding($system, ['package_name' => 'zlib', 'rule_id' => 'CVE-2022-1']);

    $ids = fn ($response): array => array_column($response->json('structuredContent.local_findings') ?? [], 'id');

    AppSecScoutServer::actingAs($this->reader)->tool(FindSimilarFindingsTool::class, ['local_finding_id' => $reference->id, 'match' => 'package'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('alerts', [])
            ->where('summary.local_findings_by_status', ['open' => 1, 'resolved' => 1])
            ->has('local_findings', 2)
            ->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(FindSimilarFindingsTool::class, ['local_finding_id' => $reference->id, 'match' => 'package', 'include_closed' => false])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('local_findings.0.id', $otherVersion->id)
            ->has('local_findings', 1)
            ->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(FindSimilarFindingsTool::class, ['local_finding_id' => $reference->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('local_findings.0.id', $resolved->id)
            ->where('local_findings.0.latest_comment.body', 'Upgraded to 3.0.')
            ->has('local_findings', 1)
            ->etc());
});

it('refuses what cannot be matched', function () {
    $noRule = SecurityEvent::factory()->create(['rule_id' => null]);
    $secret = similarFinding(SoftwareSystem::factory()->create(), ['kind' => LocalFinding::KIND_SECRET, 'package_name' => null]);

    AppSecScoutServer::actingAs($this->reader)->tool(FindSimilarFindingsTool::class, ['alert_id' => $noRule->id])
        ->assertHasErrors(['The reference finding has no rule id, so there is nothing to match on.']);
    AppSecScoutServer::actingAs($this->reader)->tool(FindSimilarFindingsTool::class, ['local_finding_id' => $secret->id, 'match' => 'package'])
        ->assertHasErrors(['match=package needs a local finding of kind vulnerability with a package name.']);
    AppSecScoutServer::actingAs($this->reader)->tool(FindSimilarFindingsTool::class, ['alert_id' => $noRule->id, 'local_finding_id' => $secret->id])
        ->assertHasErrors(['Provide alert_id or local_finding_id, not both.']);
    AppSecScoutServer::actingAs($this->reader)->tool(FindSimilarFindingsTool::class, [])
        ->assertHasErrors(['Provide alert_id or local_finding_id.']);
});

it('requires alerts.view', function () {
    AppSecScoutServer::actingAs(mcpUser(null))->tool(FindSimilarFindingsTool::class, ['alert_id' => SecurityEvent::factory()->create()->id])
        ->assertHasErrors(['Permission denied: this tool requires the alerts.view permission.']);
});
