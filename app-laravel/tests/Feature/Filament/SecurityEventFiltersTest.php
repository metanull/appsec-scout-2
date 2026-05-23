<?php

use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Filament\Support\UserViewStateStore;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventType;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SoftwareSystemLink;
use App\Models\User;

function seedFilterFixture(): array
{
    $systemA = SoftwareSystem::factory()->create(['name' => 'Payments API']);
    $systemB = SoftwareSystem::factory()->create(['name' => 'Identity API']);

    $containerA = SecurityContainer::factory()->forSystem($systemA)->create(['name' => 'Repo A']);
    $containerB = SecurityContainer::factory()->forSystem($systemB)->create(['name' => 'Repo B']);

    SecurityEvent::factory()->forSystem($systemA)->forContainer($containerA)->create([
        'source_id' => 'azdo',
        'severity' => EventSeverity::Critical,
        'state' => 'open',
        'type' => EventType::Secret,
        'title' => 'Leaked PAT in source',
        'metadata' => ['work_item_id' => 'WI-1', 'tags' => ['secret', 'hotfix'], 'cveId' => null, 'ruleId' => 'GHA-001'],
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

it('filters by software system', function () {
    [$systemA] = seedFilterFixture();

    $count = SecurityEventTableQuery::applySystem(SecurityEvent::query(), $systemA->id)->count();

    expect($count)->toBe(2);
});

it('filters by virtual system scope', function () {
    [$systemA] = seedFilterFixture();

    $link = SoftwareSystemLink::factory()->create();
    $link->members()->attach($systemA->id, ['sort_order' => 1]);

    $count = SecurityEventTableQuery::applySystemScopes(SecurityEvent::query(), ['virtual:' . $link->id])->count();

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

it('filters by work item presence', function () {
    seedFilterFixture();

    $withWorkItem = SecurityEventTableQuery::applyHasWorkItem(SecurityEvent::query(), true)->count();
    $withoutWorkItem = SecurityEventTableQuery::applyHasWorkItem(SecurityEvent::query(), false)->count();

    expect($withWorkItem)->toBe(1)
        ->and($withoutWorkItem)->toBe(2);
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

it('searches title description metadata and fulltext fallback', function () {
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
