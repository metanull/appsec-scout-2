<?php

use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Filament\Support\UserViewStateStore;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventType;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\Models\WorkItemLink;

function seedFilterFixture(): array
{
    $systemA = SoftwareSystem::factory()->create(['name' => 'Payments API']);
    $systemB = SoftwareSystem::factory()->create(['name' => 'Identity API']);

    $containerA = SecurityContainer::factory()->forSystem($systemA)->create(['name' => 'Repo A']);
    $containerB = SecurityContainer::factory()->forSystem($systemB)->create(['name' => 'Repo B']);

    $eventWithWorkItem = SecurityEvent::factory()->forSystem($systemA)->forContainer($containerA)->create([
        'source_id' => 'azdo',
        'severity' => EventSeverity::Critical,
        'state' => 'open',
        'type' => EventType::Secret,
        'title' => 'Leaked PAT in source',
        'metadata' => ['tags' => ['secret', 'hotfix'], 'cveId' => null, 'ruleId' => 'GHA-001'],
    ]);

    // Seed a WorkItemLink for the first event (replaces old metadata->work_item_id approach)
    WorkItemLink::query()->create([
        'event_id' => $eventWithWorkItem->id,
        'tracker_id' => 'jira',
        'work_item_id' => 'WI-1',
        'work_item_title' => 'Fix leaked PAT',
        'work_item_state' => 'Open',
        'work_item_url' => null,
        'created_by_user_id' => null,
        'synced_at' => now(),
    ]);

    SecurityEvent::factory()->forSystem($systemA)->forContainer($containerA)->create([
        'source_id' => 'asoc',
        'severity' => EventSeverity::High,
        'state' => 'in_progress',
        'type' => EventType::Vulnerability,
        'title' => 'SQL injection in endpoint',
        'metadata' => ['tags' => ['sast'], 'cveId' => 'CVE-2026-0001', 'ruleId' => 'CWE-89'],
    ]);

    SecurityEvent::factory()->forSystem($systemB)->forContainer($containerB)->create([
        'source_id' => 'detectify',
        'severity' => EventSeverity::Low,
        'state' => 'resolved',
        'type' => EventType::Misconfiguration,
        'title' => 'Missing security headers',
        'metadata' => ['tags' => ['web']],
    ]);

    return [$systemA, $systemB, $containerA, $containerB];
}

it('filters by severity', function () {
    seedFilterFixture();

    $count = SecurityEventTableQuery::applySeverities(SecurityEvent::query(), ['critical'])->count();

    expect($count)->toBe(1);
});

it('filters by state', function () {
    seedFilterFixture();

    $count = SecurityEventTableQuery::applyStates(SecurityEvent::query(), ['in_progress'])->count();

    expect($count)->toBe(1);
});

it('filters by source', function () {
    seedFilterFixture();

    $count = SecurityEventTableQuery::applySources(SecurityEvent::query(), ['detectify'])->count();

    expect($count)->toBe(1);
});

it('filters by software asset via the event system', function () {
    $assetA = App\Models\SoftwareAsset::factory()->create(['name' => 'Payments Platform']);
    $assetB = App\Models\SoftwareAsset::factory()->create(['name' => 'Identity Platform']);

    $systemA = SoftwareSystem::factory()->create(['software_asset_id' => $assetA->id]);
    $systemB = SoftwareSystem::factory()->create(['software_asset_id' => $assetB->id]);

    SecurityEvent::factory()->forSystem($systemA)->create();
    SecurityEvent::factory()->forSystem($systemA)->create();
    SecurityEvent::factory()->forSystem($systemB)->create();

    $count = SecurityEventTableQuery::applyAssetScopes(SecurityEvent::query(), [(string) $assetA->id])->count();
    $none = SecurityEventTableQuery::applyAssetScopes(SecurityEvent::query(), ['0'])->count();
    $noFilter = SecurityEventTableQuery::applyAssetScopes(SecurityEvent::query(), [])->count();

    expect($count)->toBe(2)
        ->and($none)->toBe(0)
        ->and($noFilter)->toBe(3);
});

it('filters by software system', function () {
    [$systemA] = seedFilterFixture();

    $count = SecurityEventTableQuery::applySystem(SecurityEvent::query(), $systemA->id)->count();

    expect($count)->toBe(2);
});

it('filters by container', function () {
    [, , $containerA] = seedFilterFixture();

    $count = SecurityEventTableQuery::applyContainer(SecurityEvent::query(), $containerA->id)->count();

    expect($count)->toBe(2);
});

it('filters by event type', function () {
    seedFilterFixture();

    $count = SecurityEventTableQuery::applyTypes(SecurityEvent::query(), ['secret'])->count();

    expect($count)->toBe(1);
});

it('filters by work item presence using work_item_links relationship', function () {
    // seedFilterFixture already seeds one event with a WorkItemLink and two without
    seedFilterFixture();

    $withWorkItem = SecurityEventTableQuery::applyHasWorkItem(SecurityEvent::query(), true)->count();
    $withoutWorkItem = SecurityEventTableQuery::applyHasWorkItem(SecurityEvent::query(), false)->count();
    $noFilter = SecurityEventTableQuery::applyHasWorkItem(SecurityEvent::query(), null)->count();

    expect($withWorkItem)->toBe(1)
        ->and($withoutWorkItem)->toBe(2)
        ->and($noFilter)->toBe(3);
});

it('filters by specific tracker and work item id', function () {
    $eventA = SecurityEvent::factory()->create();
    $eventB = SecurityEvent::factory()->create();
    $eventC = SecurityEvent::factory()->create();

    WorkItemLink::query()->create([
        'event_id' => $eventA->id,
        'tracker_id' => 'jira',
        'work_item_id' => 'PROJ-10',
        'work_item_title' => null,
        'work_item_state' => null,
        'work_item_url' => null,
        'created_by_user_id' => null,
        'synced_at' => now(),
    ]);

    WorkItemLink::query()->create([
        'event_id' => $eventB->id,
        'tracker_id' => 'jira',
        'work_item_id' => 'PROJ-10',
        'work_item_title' => null,
        'work_item_state' => null,
        'work_item_url' => null,
        'created_by_user_id' => null,
        'synced_at' => now(),
    ]);

    WorkItemLink::query()->create([
        'event_id' => $eventC->id,
        'tracker_id' => 'jira',
        'work_item_id' => 'PROJ-99',
        'work_item_title' => null,
        'work_item_state' => null,
        'work_item_url' => null,
        'created_by_user_id' => null,
        'synced_at' => now(),
    ]);

    $count = SecurityEventTableQuery::applyWorkItem(SecurityEvent::query(), 'jira', 'PROJ-10')->count();
    $empty = SecurityEventTableQuery::applyWorkItem(SecurityEvent::query(), 'jira', '')->count();
    $nullFilter = SecurityEventTableQuery::applyWorkItem(SecurityEvent::query(), null, null)->count();

    expect($count)->toBe(2)
        ->and($empty)->toBe(3)
        ->and($nullFilter)->toBe(3);
});

it('filters by pending sync dirty state', function () {
    SecurityEvent::factory()->create(['is_dirty' => true]);
    SecurityEvent::factory()->create(['is_dirty' => false]);

    expect(SecurityEvent::query()->where('is_dirty', true)->count())->toBe(1)
        ->and(SecurityEvent::query()->where('is_dirty', false)->count())->toBe(1);
});

it('filters by tags', function () {
    seedFilterFixture();

    $count = SecurityEventTableQuery::applyTags(SecurityEvent::query(), ['sast'])->count();

    expect($count)->toBe(1);
});

it('searches title description and metadata with portable like filters', function () {
    seedFilterFixture();

    $countByTitle = SecurityEventTableQuery::applySearch(SecurityEvent::query(), 'Leaked PAT')->count();
    $countByMetadata = SecurityEventTableQuery::applySearch(SecurityEvent::query(), 'CVE-2026-0001')->count();

    expect($countByTitle)->toBe(1)
        ->and($countByMetadata)->toBe(1);
});

it('persists view state per user and view identifier', function () {
    $user = User::factory()->create();

    $store = app(UserViewStateStore::class);

    $store->save($user->id, 'security-events:list', [
        'filters' => ['severity' => ['values' => ['critical']]],
        'search' => 'secret',
        'sort' => 'last_seen_at:desc',
    ]);

    $state = $store->load($user->id, 'security-events:list');

    expect($state['filters']['severity']['values'])->toBe(['critical'])
        ->and($state['search'])->toBe('secret')
        ->and($state['sort'])->toBe('last_seen_at:desc');
});
