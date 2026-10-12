<?php

use App\Audit\AuditLog;
use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Triage\ChangeAlertSeverityTool;
use App\Mcp\Tools\Triage\ChangeAlertStateTool;
use App\Mcp\Tools\Triage\ChangeLocalFindingSeverityTool;
use App\Mcp\Tools\Triage\ChangeLocalFindingStatusTool;
use App\Mcp\Tools\Triage\CommentAlertTool;
use App\Mcp\Tools\Triage\CommentLocalFindingTool;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\EventComment;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    Queue::fake();
    $this->triager = mcpUser('Triage');
});

afterEach(function () {
    Queue::assertNothingPushed();
});

/** @param array<string, mixed> $attributes */
function triageFinding(array $attributes = []): LocalFinding
{
    static $sequence = 0;
    $sequence++;
    $container = SecurityContainer::factory()->create();

    return LocalFinding::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $container->software_system_id,
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'secret-' . $sequence,
        'title' => 'Secret ' . $sequence,
        'file_path' => 'appsettings.json',
        'severity' => 'HIGH',
        ...$attributes,
    ]);
}

it('describes an alert state change without applying it by default', function () {
    $event = SecurityEvent::factory()->create(['state' => EventState::Open]);

    AppSecScoutServer::actingAs($this->triager)->tool(ChangeAlertStateTool::class, [
        'alert_ids' => [$event->id],
        'state' => 'dismissed',
        'comment' => 'False positive: test fixture only.',
    ])->assertOk()->assertStructuredContent(fn ($json) => $json
        ->where('dry_run', true)
        ->where('changes.0.id', $event->id)
        ->where('changes.0.state', 'open')
        ->where('changes.0.new_pending_state', 'dismissed')
        ->etc());

    expect($event->fresh()?->pending_state)->toBeNull()
        ->and($event->fresh()?->is_dirty)->toBeFalse()
        ->and(EventComment::query()->count())->toBe(0);
});

it('applies alert state changes as pending, comments them and audits them as mcp', function () {
    $events = SecurityEvent::factory()->count(2)->create();

    AppSecScoutServer::actingAs($this->triager)->tool(ChangeAlertStateTool::class, [
        'alert_ids' => $events->pluck('id')->all(),
        'state' => 'resolved',
        'comment' => 'Fixed by the upgrade in PR 1234.',
        'dry_run' => false,
    ])->assertOk()->assertStructuredContent(fn ($json) => $json
        ->where('dry_run', false)
        ->where('applied.0.pending_state', 'resolved')
        ->where('applied.0.is_dirty', true)
        ->where('applied.1.state', 'open')
        ->etc());

    foreach ($events as $event) {
        expect($event->fresh()?->pending_state)->toBe(EventState::Resolved)
            ->and($event->comments()->value('body'))->toBe('[State change: resolved] Fixed by the upgrade in PR 1234.');
    }

    $audit = AuditLog::query()->where('action', 'bulk_state_change')->sole();

    expect($audit->user_id)->toBe($this->triager->id)
        ->and($audit->payload_json['channel'])->toBe('mcp');
});

it('needs alerts.bulk-edit to change several findings at once', function () {
    $editor = mcpUser('Reader');
    $editor->givePermissionTo('alerts.edit');
    $events = SecurityEvent::factory()->count(2)->create();
    $findings = collect([triageFinding(), triageFinding()]);

    AppSecScoutServer::actingAs($editor)->tool(ChangeAlertStateTool::class, ['alert_ids' => $events->pluck('id')->all(), 'state' => 'resolved', 'comment' => 'Fixed upstream already.'])
        ->assertHasErrors(['Permission denied: this tool requires the alerts.bulk-edit permission.']);
    AppSecScoutServer::actingAs($editor)->tool(ChangeAlertSeverityTool::class, ['alert_ids' => $events->pluck('id')->all(), 'severity' => 'low', 'comment' => 'Not reachable from outside.'])
        ->assertHasErrors(['Permission denied: this tool requires the alerts.bulk-edit permission.']);
    AppSecScoutServer::actingAs($editor)->tool(ChangeLocalFindingStatusTool::class, ['finding_ids' => $findings->pluck('id')->all(), 'status' => 'dismissed', 'comment' => 'Test credentials only.'])
        ->assertHasErrors(['Permission denied: this tool requires the alerts.bulk-edit permission.']);

    AppSecScoutServer::actingAs($editor)->tool(ChangeAlertStateTool::class, ['alert_ids' => [$events[0]->id], 'state' => 'resolved', 'comment' => 'Fixed upstream already.'])
        ->assertOk();
});

it('changes nothing when an id is unknown or the comment is too short', function () {
    $event = SecurityEvent::factory()->create();

    AppSecScoutServer::actingAs($this->triager)->tool(ChangeAlertStateTool::class, [
        'alert_ids' => [$event->id, 999999],
        'state' => 'resolved',
        'comment' => 'Fixed by the upgrade in PR 1234.',
        'dry_run' => false,
    ])->assertHasErrors(['No alert with id(s): 999999. Nothing was changed.']);

    AppSecScoutServer::actingAs($this->triager)->tool(ChangeAlertSeverityTool::class, [
        'alert_ids' => [$event->id],
        'severity' => 'low',
        'comment' => 'too short',
        'dry_run' => false,
    ])->assertHasErrors();

    expect($event->fresh()?->is_dirty)->toBeFalse()
        ->and(EventComment::query()->count())->toBe(0);
});

it('applies alert severity changes as pending', function () {
    $event = SecurityEvent::factory()->create(['severity' => EventSeverity::Critical]);

    AppSecScoutServer::actingAs($this->triager)->tool(ChangeAlertSeverityTool::class, [
        'alert_ids' => [$event->id],
        'severity' => 'low',
        'comment' => 'Only reachable from the admin network.',
        'dry_run' => false,
    ])->assertOk()->assertStructuredContent(fn ($json) => $json
        ->where('applied.0.severity', 'critical')
        ->where('applied.0.pending_severity', 'low')
        ->etc());

    expect($event->fresh()?->pending_severity)->toBe(EventSeverity::Low);
});

it('comments on an alert and on a local finding as the acting user', function () {
    $event = SecurityEvent::factory()->create();
    $finding = triageFinding();

    AppSecScoutServer::actingAs($this->triager)->tool(CommentAlertTool::class, ['alert_id' => $event->id, 'body' => 'Asked the team to confirm.'])
        ->assertOk();
    AppSecScoutServer::actingAs($this->triager)->tool(CommentLocalFindingTool::class, ['finding_id' => $finding->id, 'body' => 'Rotating the key.'])
        ->assertOk();

    expect($event->comments()->sole()->author_user_id)->toBe($this->triager->id)
        ->and($finding->comments()->sole()->body)->toBe('Rotating the key.');
});

it('applies local finding status and severity changes immediately', function () {
    $finding = triageFinding();

    AppSecScoutServer::actingAs($this->triager)->tool(ChangeLocalFindingStatusTool::class, [
        'finding_ids' => [$finding->id],
        'status' => 'dismissed',
        'comment' => 'Sample key from the vendor docs.',
    ])->assertStructuredContent(fn ($json) => $json->where('dry_run', true)->etc());

    expect($finding->fresh()?->status)->toBe(EventState::Open);

    AppSecScoutServer::actingAs($this->triager)->tool(ChangeLocalFindingStatusTool::class, [
        'finding_ids' => [$finding->id],
        'status' => 'dismissed',
        'comment' => 'Sample key from the vendor docs.',
        'dry_run' => false,
    ])->assertOk();

    AppSecScoutServer::actingAs($this->triager)->tool(ChangeLocalFindingSeverityTool::class, [
        'finding_ids' => [$finding->id],
        'severity' => 'informational',
        'comment' => 'Sample key from the vendor docs.',
        'dry_run' => false,
    ])->assertOk()->assertStructuredContent(fn ($json) => $json
        ->where('applied.0.reported_severity', 'HIGH')
        ->where('applied.0.overridden_severity', 'informational')
        ->etc());

    expect($finding->fresh()?->status)->toBe(EventState::Dismissed)
        ->and($finding->fresh()?->overridden_severity)->toBe(EventSeverity::Informational)
        ->and($finding->comments()->count())->toBe(2);
});

it('refuses the write tools without alerts.edit', function (string $tool, array $arguments) {
    AppSecScoutServer::actingAs(mcpUser('Reader'))->tool($tool, $arguments)
        ->assertHasErrors(['Permission denied: this tool requires the alerts.edit permission.']);
})->with([
    'alert state' => [ChangeAlertStateTool::class, ['alert_ids' => [1], 'state' => 'resolved', 'comment' => 'Fixed upstream already.']],
    'alert severity' => [ChangeAlertSeverityTool::class, ['alert_ids' => [1], 'severity' => 'low', 'comment' => 'Not reachable at all.']],
    'alert comment' => [CommentAlertTool::class, ['alert_id' => 1, 'body' => 'Hi']],
    'finding status' => [ChangeLocalFindingStatusTool::class, ['finding_ids' => [1], 'status' => 'resolved', 'comment' => 'Fixed upstream already.']],
    'finding severity' => [ChangeLocalFindingSeverityTool::class, ['finding_ids' => [1], 'severity' => 'low', 'comment' => 'Not reachable at all.']],
    'finding comment' => [CommentLocalFindingTool::class, ['finding_id' => 1, 'body' => 'Hi']],
]);
