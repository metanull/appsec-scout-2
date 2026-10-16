<?php

use App\Audit\AuditLog;
use App\Credentials\Vault;
use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Trackers\CreateAlertsWorkItemTool;
use App\Mcp\Tools\Trackers\CreateLocalFindingsWorkItemTool;
use App\Mcp\Tools\Trackers\GetTrackerOptionsTool;
use App\Models\LocalFinding;
use App\Models\LocalFindingWorkItemLink;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\TrackerProjectLink;
use App\Models\WorkItemLink;
use App\Trackers\Dto\ProjectDto;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    Queue::fake();
    $this->tracker = bindFakeWorkItemTracker(
        (new FakeTracker)->withProjects(new ProjectDto(key: 'APP', name: 'Application'))->withItemTypes('Bug', 'Task'),
    );
    $this->planner = mcpUser('Plan');
    app(Vault::class)->set('fake-tracker.token', $this->planner->id, 'personal-token');
});

afterEach(function () {
    Queue::assertNothingPushed();
});

function trackedFinding(SecurityContainer $container, string $rule): LocalFinding
{
    return LocalFinding::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $container->software_system_id,
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => $rule,
        'title' => "{$rule} in lodash",
        'file_path' => 'package-lock.json',
        'severity' => 'HIGH',
        'package_name' => 'lodash',
        'package_version' => '4.17.19',
    ]);
}

it('lists tracker capabilities, projects, item types and the suggested project', function () {
    $system = SoftwareSystem::factory()->create();
    TrackerProjectLink::factory()->forSystem($system)->create(['tracker_id' => 'fake-tracker', 'project_key' => 'APP', 'project_name' => 'Application', 'is_default' => true]);
    $event = SecurityEvent::factory()->forSystem($system)->create();

    AppSecScoutServer::actingAs($this->planner)->tool(GetTrackerOptionsTool::class, [
        'tracker_id' => 'fake-tracker',
        'project_key' => 'APP',
        'alert_ids' => [$event->id],
    ])->assertOk()->assertStructuredContent(fn ($json) => $json
        ->where('tracker.id', 'fake-tracker')
        ->where('capabilities.priority', true)
        ->where('projects', [['key' => 'APP', 'name' => 'Application', 'url' => null]])
        ->where('item_types', ['Bug', 'Task'])
        ->where('suggested_project.project_key', 'APP')
        ->etc());
});

it('refuses to mix alerts and local findings in one suggestion', function () {
    AppSecScoutServer::actingAs($this->planner)->tool(GetTrackerOptionsTool::class, [
        'tracker_id' => 'fake-tracker',
        'alert_ids' => [SecurityEvent::factory()->create()->id],
        'finding_ids' => [trackedFinding(SecurityContainer::factory()->create(), 'CVE-1')->id],
    ])->assertHasErrors(['Pass alert_ids or finding_ids, not both: alerts and local findings never share a work item.']);
});

it('previews an alerts work item without creating it by default', function () {
    $events = SecurityEvent::factory()->count(2)->create();

    AppSecScoutServer::actingAs($this->planner)->tool(CreateAlertsWorkItemTool::class, [
        'alert_ids' => $events->pluck('id')->all(),
        'tracker_id' => 'fake-tracker',
        'project_key' => 'APP',
        'item_type' => 'Bug',
    ])->assertOk()->assertStructuredContent(fn ($json) => $json
        ->where('dry_run', true)
        ->where('project_key', 'APP')
        ->where('linked_alert_ids', $events->pluck('id')->sort()->values()->all())
        ->whereType('title', 'string')
        ->whereType('description', 'string')
        ->etc());

    expect($this->tracker->createCalls)->toBe(0)
        ->and(WorkItemLink::query()->count())->toBe(0);
});

it('creates one work item for several alerts and returns it', function () {
    $events = SecurityEvent::factory()->count(2)->create();

    AppSecScoutServer::actingAs($this->planner)->tool(CreateAlertsWorkItemTool::class, [
        'alert_ids' => $events->pluck('id')->all(),
        'tracker_id' => 'fake-tracker',
        'project_key' => 'APP',
        'item_type' => 'Bug',
        'labels' => ['security'],
        'priority' => 'High',
        'dry_run' => false,
    ])->assertOk()->assertStructuredContent(fn ($json) => $json
        ->where('dry_run', false)
        ->where('work_item_id', 'APP#1')
        ->where('url', 'https://tracker.test/APP%231')
        ->etc());

    expect($this->tracker->createCalls)->toBe(1)
        ->and($this->tracker->latestCreateWorkItemRequest?->labels)->toBe(['security'])
        ->and(WorkItemLink::query()->where('work_item_id', 'APP#1')->pluck('event_id')->sort()->values()->all())
        ->toBe($events->pluck('id')->sort()->values()->all())
        ->and(AuditLog::query()->where('action', 'work_item_created')->sole()->payload_json['channel'])->toBe('mcp');
});

it('creates one work item for several local findings and returns it', function () {
    $container = SecurityContainer::factory()->create();
    $findings = collect([trackedFinding($container, 'CVE-1'), trackedFinding($container, 'CVE-2')]);

    AppSecScoutServer::actingAs($this->planner)->tool(CreateLocalFindingsWorkItemTool::class, [
        'finding_ids' => $findings->pluck('id')->all(),
        'tracker_id' => 'fake-tracker',
        'project_key' => 'APP',
        'item_type' => 'Task',
    ])->assertStructuredContent(fn ($json) => $json
        ->where('dry_run', true)
        ->where('title', fn (string $title): bool => str_contains($title, '2 findings'))
        ->etc());

    expect($this->tracker->createCalls)->toBe(0);

    AppSecScoutServer::actingAs($this->planner)->tool(CreateLocalFindingsWorkItemTool::class, [
        'finding_ids' => $findings->pluck('id')->all(),
        'tracker_id' => 'fake-tracker',
        'project_key' => 'APP',
        'item_type' => 'Task',
        'dry_run' => false,
    ])->assertOk()->assertStructuredContent(fn ($json) => $json->where('work_item_id', 'APP#1')->etc());

    expect(LocalFindingWorkItemLink::query()->where('work_item_id', 'APP#1')->count())->toBe(2);
});

it('fails clearly when the user has no personal tracker credentials', function () {
    $user = mcpUser('Plan');
    $event = SecurityEvent::factory()->create();
    app(Vault::class)->set('fake-tracker.token', null, 'system-token');

    AppSecScoutServer::actingAs($user)->tool(CreateAlertsWorkItemTool::class, [
        'alert_ids' => [$event->id],
        'tracker_id' => 'fake-tracker',
        'project_key' => 'APP',
        'item_type' => 'Bug',
        'dry_run' => false,
    ])->assertHasErrors(['Missing personal Fake Tracker credential(s): Token. Add them on your Profile integrations page.']);

    expect($this->tracker->createCalls)->toBe(0);
});

it('rejects an unknown tracker or an unsupported item type', function () {
    $event = SecurityEvent::factory()->create();

    AppSecScoutServer::actingAs($this->planner)->tool(CreateAlertsWorkItemTool::class, [
        'alert_ids' => [$event->id], 'tracker_id' => 'nope', 'project_key' => 'APP', 'item_type' => 'Bug',
    ])->assertHasErrors();

    AppSecScoutServer::actingAs($this->planner)->tool(CreateAlertsWorkItemTool::class, [
        'alert_ids' => [$event->id], 'tracker_id' => 'fake-tracker', 'project_key' => 'APP', 'item_type' => 'Epic',
    ])->assertHasErrors(['Fake Tracker item types are: Bug, Task.']);
});

it('refuses the tracker tools without work-items.create', function (string $tool, array $arguments) {
    AppSecScoutServer::actingAs(mcpUser('Triage'))->tool($tool, $arguments)
        ->assertHasErrors(['Permission denied: this tool requires the work-items.create permission.']);
})->with([
    'options' => [GetTrackerOptionsTool::class, ['tracker_id' => 'fake-tracker']],
    'alerts' => [CreateAlertsWorkItemTool::class, ['alert_ids' => [1], 'tracker_id' => 'fake-tracker', 'project_key' => 'APP', 'item_type' => 'Bug']],
    'local findings' => [CreateLocalFindingsWorkItemTool::class, ['finding_ids' => [1], 'tracker_id' => 'fake-tracker', 'project_key' => 'APP', 'item_type' => 'Bug']],
]);

it('previews the comment and refuses an oversized one on a dry run', function () {
    $event = SecurityEvent::factory()->create();
    $arguments = ['alert_ids' => [$event->id], 'tracker_id' => 'fake-tracker', 'project_key' => 'APP', 'item_type' => 'Bug'];

    AppSecScoutServer::actingAs($this->planner)->tool(CreateAlertsWorkItemTool::class, [...$arguments, 'comment' => 'Confirmed true positive.'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('dry_run', true)->where('comment', 'Confirmed true positive.')->etc());

    AppSecScoutServer::actingAs($this->planner)->tool(CreateAlertsWorkItemTool::class, [...$arguments, 'comment' => str_repeat('a', 32768)])
        ->assertHasErrors(['The comment is 32768 characters long; Fake Tracker accepts at most 32767. Nothing was created.']);

    expect($this->tracker->createCalls)->toBe(0);
});

it('posts the comment on the created work item and reports a failed post', function () {
    $event = SecurityEvent::factory()->create();
    $arguments = ['alert_ids' => [$event->id], 'tracker_id' => 'fake-tracker', 'project_key' => 'APP', 'item_type' => 'Bug', 'comment' => 'Confirmed true positive.', 'dry_run' => false];

    AppSecScoutServer::actingAs($this->planner)->tool(CreateAlertsWorkItemTool::class, $arguments)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('work_item_id', 'APP#1')->where('comment_posted', true)->where('comment_error', null)->etc());

    expect($this->tracker->comments)->toBe([['key' => 'APP#1', 'markdown' => 'Confirmed true positive.']]);

    $this->tracker->withCommentFailure('403 Forbidden');

    AppSecScoutServer::actingAs($this->planner)->tool(CreateAlertsWorkItemTool::class, $arguments)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('work_item_id', 'APP#2')->where('comment_posted', false)->where('comment_error', '403 Forbidden')->etc());

    expect(WorkItemLink::query()->where('work_item_id', 'APP#2')->exists())->toBeTrue();
});
