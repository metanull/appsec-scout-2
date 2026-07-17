<?php

use App\Assets\LocalFindingWorkItemService;
use App\Audit\AuditLog;
use App\Models\LocalFinding;
use App\Models\LocalFindingWorkItemLink;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\TrackerProjectLink;
use App\Models\User;
use App\Trackers\Dto\WorkItemDto;
use Database\Seeders\RolePermissionSeeder;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('creates a work item for a single-id array and links it', function () {
    $tracker = bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    $container = SecurityContainer::factory()->create();
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-56201',
        'title' => 'Jinja sandbox breakout',
        'severity' => 'MEDIUM',
        'file_path' => 'requirements.txt',
        'start_line' => 8,
        'package_name' => 'Jinja2',
        'package_version' => '3.1.4',
        'description' => 'Sandbox escape via crafted template.',
    ]);

    app(LocalFindingWorkItemService::class)->createForFindings(
        findingIds: [$finding->id],
        userId: $user->id,
        trackerId: 'fake-tracker',
        projectKey: 'APP',
        itemType: 'Bug',
        labels: ['security', 'appsec-scout'],
    );

    $link = LocalFindingWorkItemLink::query()->first();

    expect($tracker->createCalls)->toBe(1)
        ->and($tracker->latestCreateWorkItemRequest?->title)->toContain('Jinja sandbox breakout')
        ->and($tracker->latestCreateWorkItemRequest?->description)->toContain('Sandbox escape via crafted template.')
        ->and($link)->not->toBeNull()
        ->and($link?->local_finding_id)->toBe($finding->id)
        ->and($link?->tracker_id)->toBe('fake-tracker')
        ->and(AuditLog::query()->where('action', 'work_item_created')->where('subject_id', (string) $finding->id)->exists())->toBeTrue();
});

it('creates one grouped work item for several findings with a severity table and per-kind occurrences', function () {
    $tracker = bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->create(['software_system_id' => $system->id]);

    $secret = $container->localFindings()->create([
        'software_system_id' => $system->id,
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'severity' => 'CRITICAL',
        'file_path' => 'config/services.php',
        'start_line' => 12,
        'description' => 'A plaintext credential was committed.',
    ]);
    $vuln = $container->localFindings()->create([
        'software_system_id' => $system->id,
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-56201',
        'title' => 'Jinja sandbox breakout',
        'severity' => 'MEDIUM',
        'file_path' => 'requirements.txt',
        'package_name' => 'Jinja2',
        'package_version' => '3.1.4',
        'description' => 'Sandbox escape via crafted template.',
    ]);
    $vuln2 = $container->localFindings()->create([
        'software_system_id' => $system->id,
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-99999',
        'title' => 'Another vulnerable dependency',
        'severity' => 'HIGH',
        'file_path' => 'requirements.txt',
        'package_name' => 'requests',
        'package_version' => '2.0.0',
    ]);

    app(LocalFindingWorkItemService::class)->createForFindings(
        findingIds: [$secret->id, $vuln->id, $vuln2->id],
        userId: $user->id,
        trackerId: 'fake-tracker',
        projectKey: 'APP',
        itemType: 'Bug',
    );

    $links = LocalFindingWorkItemLink::query()->get();
    $workItemIds = $links->pluck('work_item_id')->unique()->values();
    $audit = AuditLog::query()->where('action', 'work_item_created')->latest('id')->first();
    $description = $tracker->latestCreateWorkItemRequest?->description ?? '';

    expect($tracker->createCalls)->toBe(1)
        ->and($links)->toHaveCount(3)
        ->and($workItemIds)->toHaveCount(1)
        ->and($audit?->payload_json['grouped'])->toBeTrue()
        ->and($audit?->payload_json['finding_ids'])->toHaveCount(3)
        ->and($description)->toContain('| Severity | Count |')
        ->and($description)->toContain('### Occurrences')
        ->and($description)->toContain('config/services.php:12 (generic-api-key)')
        ->and($description)->toContain('requirements.txt (CVE-2024-56201)');
});

it('produces the single-finding title and description for a one-element array', function () {
    $tracker = bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    $container = SecurityContainer::factory()->create();
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
        'start_line' => 5,
    ]);

    app(LocalFindingWorkItemService::class)->createForFindings(
        findingIds: [$finding->id],
        userId: $user->id,
        trackerId: 'fake-tracker',
        projectKey: 'APP',
        itemType: 'Bug',
    );

    expect($tracker->latestCreateWorkItemRequest?->title)->toBe('secret: Hardcoded API key (config/services.php:5)')
        ->and($tracker->latestCreateWorkItemRequest?->description)->not->toContain('| Severity | Count |');
});

it('links an existing work item to several findings', function () {
    $tracker = bindFakeWorkItemTracker((new FakeTracker)->withExistingWorkItem(new WorkItemDto(
        id: 'APP#7',
        projectKey: 'APP',
        title: 'Existing tracker item',
        state: 'Open',
        url: 'https://tracker.test/APP%237',
    )));
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    $container = SecurityContainer::factory()->create();
    $findingA = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);
    $findingB = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Second hardcoded key',
        'file_path' => 'config/other.php',
    ]);

    app(LocalFindingWorkItemService::class)->linkExisting(
        findingIds: [$findingA->id, $findingB->id],
        userId: $user->id,
        trackerId: 'fake-tracker',
        workItemId: 'APP#7',
    );

    expect($tracker->getCalls)->toBe(1)
        ->and(LocalFindingWorkItemLink::count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'work_item_linked')->where('subject_id', (string) $findingA->id)->exists())->toBeTrue();
});

it('rejects the whole batch when one finding is already linked to the work item', function () {
    bindFakeWorkItemTracker((new FakeTracker)->withExistingWorkItem(new WorkItemDto(
        id: 'APP#7',
        projectKey: 'APP',
        title: 'Existing tracker item',
        state: 'Open',
    )));
    $user = User::factory()->create();
    $container = SecurityContainer::factory()->create();
    $findingA = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);
    $findingB = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Second hardcoded key',
        'file_path' => 'config/other.php',
    ]);

    $service = app(LocalFindingWorkItemService::class);
    $service->linkExisting(findingIds: [$findingA->id], userId: $user->id, trackerId: 'fake-tracker', workItemId: 'APP#7');

    expect(fn () => $service->linkExisting(findingIds: [$findingA->id, $findingB->id], userId: $user->id, trackerId: 'fake-tracker', workItemId: 'APP#7'))
        ->toThrow(RuntimeException::class)
        ->and(LocalFindingWorkItemLink::query()->where('local_finding_id', $findingB->id)->exists())->toBeFalse();
});

it('learns container and system tracker project mappings when creating a work item', function () {
    bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->create(['software_system_id' => $system->id]);
    $finding = $container->localFindings()->create([
        'software_system_id' => $system->id,
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);

    app(LocalFindingWorkItemService::class)->createForFindings(
        findingIds: [$finding->id],
        userId: $user->id,
        trackerId: 'fake-tracker',
        projectKey: 'APP',
        itemType: 'Bug',
    );

    expect(TrackerProjectLink::query()
        ->where('owner_type', SecurityContainer::class)
        ->where('owner_id', $container->id)
        ->where('project_key', 'APP')
        ->exists())->toBeTrue()
        ->and(TrackerProjectLink::query()
            ->where('owner_type', SoftwareSystem::class)
            ->where('owner_id', $system->id)
            ->where('project_key', 'APP')
            ->exists())->toBeTrue();
});

it('does not duplicate learned mappings on repeated create and link', function () {
    bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->create(['software_system_id' => $system->id]);
    $finding = $container->localFindings()->create([
        'software_system_id' => $system->id,
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);

    app(LocalFindingWorkItemService::class)->createForFindings([$finding->id], $user->id, 'fake-tracker', 'APP', 'Bug');

    bindFakeWorkItemTracker((new FakeTracker)->withExistingWorkItem(new WorkItemDto('APP#2', 'APP', 'Item 2', 'Open')));
    app(LocalFindingWorkItemService::class)->linkExisting([$finding->id], $user->id, 'fake-tracker', 'APP#2', 'APP');

    expect(TrackerProjectLink::query()->where('project_key', 'APP')->count())->toBe(2);
});

it('learns only a system-level mapping for a finding without a container owner', function () {
    bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    $system = SoftwareSystem::factory()->create();
    $finding = LocalFinding::query()->create([
        'owner_type' => SoftwareSystem::class,
        'owner_id' => $system->id,
        'software_system_id' => $system->id,
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);

    app(LocalFindingWorkItemService::class)->createForFindings([$finding->id], $user->id, 'fake-tracker', 'APP', 'Bug');

    expect(TrackerProjectLink::query()->count())->toBe(1)
        ->and(TrackerProjectLink::query()->first()?->owner_type)->toBe(SoftwareSystem::class);
});

it('throws before any tracker call when a finding id cannot be loaded', function () {
    $tracker = bindFakeWorkItemTracker(new FakeTracker);
    $user = User::factory()->create();
    $container = SecurityContainer::factory()->create();
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);

    $service = app(LocalFindingWorkItemService::class);

    expect(fn () => $service->createForFindings([$finding->id, $finding->id + 999], $user->id, 'fake-tracker', 'APP', 'Bug'))
        ->toThrow(RuntimeException::class)
        ->and($tracker->createCalls)->toBe(0);
});

it('unlinks a work item and records an audit row', function () {
    $container = SecurityContainer::factory()->create();
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);
    $link = LocalFindingWorkItemLink::query()->create([
        'local_finding_id' => $finding->id,
        'tracker_id' => 'github',
        'work_item_id' => 'octo/app#101',
        'work_item_title' => 'Linked work item',
    ]);

    app(LocalFindingWorkItemService::class)->unlink($link);

    expect(LocalFindingWorkItemLink::count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'work_item_unlinked')->where('subject_id', (string) $finding->id)->exists())->toBeTrue();
});
