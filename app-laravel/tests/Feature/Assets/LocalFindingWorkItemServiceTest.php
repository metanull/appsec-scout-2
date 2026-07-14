<?php

use App\Assets\LocalFindingWorkItemService;
use App\Audit\AuditLog;
use App\Models\LocalFinding;
use App\Models\LocalFindingWorkItemLink;
use App\Models\SecurityContainer;
use App\Models\User;
use App\Trackers\Dto\WorkItemDto;
use Database\Seeders\RolePermissionSeeder;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('creates a work item for a local finding and links it', function () {
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

    app(LocalFindingWorkItemService::class)->createForFinding(
        finding: $finding,
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

it('links an existing work item to a local finding', function () {
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
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);

    app(LocalFindingWorkItemService::class)->linkExisting(
        finding: $finding,
        userId: $user->id,
        trackerId: 'fake-tracker',
        workItemId: 'APP#7',
    );

    expect($tracker->getCalls)->toBe(1)
        ->and(LocalFindingWorkItemLink::count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'work_item_linked')->where('subject_id', (string) $finding->id)->exists())->toBeTrue();
});

it('rejects linking the same work item to a finding twice', function () {
    bindFakeWorkItemTracker((new FakeTracker)->withExistingWorkItem(new WorkItemDto(
        id: 'APP#7',
        projectKey: 'APP',
        title: 'Existing tracker item',
        state: 'Open',
    )));
    $user = User::factory()->create();
    $container = SecurityContainer::factory()->create();
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);

    $service = app(LocalFindingWorkItemService::class);
    $service->linkExisting(finding: $finding, userId: $user->id, trackerId: 'fake-tracker', workItemId: 'APP#7');

    expect(fn () => $service->linkExisting(finding: $finding, userId: $user->id, trackerId: 'fake-tracker', workItemId: 'APP#7'))
        ->toThrow(RuntimeException::class);
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
