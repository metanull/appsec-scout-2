<?php

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\EventComment;
use App\Models\SecurityContainer;
use App\Models\SecurityContainerLink;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SoftwareSystemLink;
use App\Models\WorkItemLink;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// --- SoftwareSystem ---

it('creates a software system with correct casts', function () {
    $system = SoftwareSystem::factory()->create([
        'metadata' => ['key' => 'value'],
        'first_seen_at' => '2025-01-01 00:00:00',
        'synced_at' => '2025-06-01 00:00:00',
    ]);

    expect($system->metadata)->toBe(['key' => 'value'])
        ->and($system->first_seen_at)->toBeInstanceOf(Carbon::class)
        ->and($system->synced_at)->toBeInstanceOf(Carbon::class);
});

it('enforces unique constraint on software_systems (source_id, source_system_id)', function () {
    SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'proj-1']);

    expect(fn () => SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'proj-1']))
        ->toThrow(QueryException::class);
});

it('allows same source_system_id for different sources', function () {
    $azdoSystem = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'same-id']);
    $asocSystem = SoftwareSystem::factory()->create(['source_id' => 'asoc', 'source_system_id' => 'same-id']);

    expect(SoftwareSystem::query()->whereKey([$azdoSystem->id, $asocSystem->id])->count())->toBe(2);
});

// --- SecurityContainer ---

it('creates a security container linked to a system', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    expect($container->software_system_id)->toBe($system->id)
        ->and($container->softwareSystem->id)->toBe($system->id);
});

it('enforces unique constraint on security_containers (software_system_id, source_container_id)', function () {
    $system = SoftwareSystem::factory()->create();
    SecurityContainer::factory()->forSystem($system)->create(['source_container_id' => 'repo-1']);

    expect(fn () => SecurityContainer::factory()->forSystem($system)->create(['source_container_id' => 'repo-1']))
        ->toThrow(QueryException::class);
});

// --- SecurityEvent ---

it('casts severity, state, type enums correctly', function () {
    $event = SecurityEvent::factory()->create([
        'severity' => 'critical',
        'state' => 'open',
        'type' => 'vulnerability',
    ]);

    expect($event->severity)->toBe(EventSeverity::Critical)
        ->and($event->state)->toBe(EventState::Open)
        ->and($event->type)->toBe(EventType::Vulnerability);
});

it('casts metadata as array', function () {
    $event = SecurityEvent::factory()->create([
        'metadata' => ['cve' => 'CVE-2020-1234', 'package' => ['name' => 'lodash']],
    ]);

    expect($event->metadata)->toBeArray()
        ->and($event->metadata['cve'])->toBe('CVE-2020-1234');
});

it('casts is_dirty as boolean', function () {
    $event = SecurityEvent::factory()->dirty()->create();

    expect($event->is_dirty)->toBeTrue()
        ->and($event->pending_state)->toBe(EventState::Resolved);
});

it('enforces unique constraint on security_events (source_id, source_event_id)', function () {
    $system = SoftwareSystem::factory()->create();
    SecurityEvent::factory()->forSystem($system)->create(['source_id' => 'azdo', 'source_event_id' => '1001']);

    expect(fn () => SecurityEvent::factory()->forSystem($system)->create(['source_id' => 'azdo', 'source_event_id' => '1001']))
        ->toThrow(QueryException::class);
});

it('loads relations: system and container', function () {
    $container = SecurityContainer::factory()->create();
    $event = SecurityEvent::factory()->forContainer($container)->create();

    $loaded = SecurityEvent::with(['softwareSystem', 'container'])->find($event->id);

    expect($loaded->softwareSystem->id)->toBe($container->software_system_id)
        ->and($loaded->container->id)->toBe($container->id);
});

it('stores long source and version-control urls for upstream alert locations', function () {
    $longUrl = 'https://dev.azure.com:443/example/project/_git/repository?path=%2F' . str_repeat('nested%2F', 40) . 'appsettings.json&version=GC' . str_repeat('a', 40) . '&line=15&lineEnd=15&lineStartColumn=68&lineEndColumn=74&lineStyle=plain';

    $event = SecurityEvent::factory()->create([
        'url' => $longUrl,
        'file_path' => str_repeat('src/deeply/nested/', 25) . 'appsettings.json',
        'version_control_url' => $longUrl,
    ]);

    $event->refresh();

    expect($event->url)->toBe($longUrl)
        ->and($event->version_control_url)->toBe($longUrl);
});

// --- EventComment ---

it('creates event comment linked to event', function () {
    $event = SecurityEvent::factory()->create();
    EventComment::factory()->create(['event_id' => $event->id, 'body' => 'Test comment']);

    expect($event->comments->first()->body)->toBe('Test comment');
});

it('cascades work item links when event is removed', function () {
    $event = SecurityEvent::factory()->create();

    WorkItemLink::query()->create([
        'event_id' => $event->id,
        'tracker_id' => 'github',
        'work_item_id' => 'octo-org/appsec-scout#101',
        'work_item_url' => 'https://github.test/octo-org/appsec-scout/issues/101',
        'work_item_title' => 'Grouped secret findings',
        'work_item_state' => 'Open',
    ]);

    $event->delete();

    expect(WorkItemLink::count())->toBe(0);
});

it('loads grouped work item links by tracker and work item id', function () {
    $events = SecurityEvent::factory()->count(3)->create();

    $primary = WorkItemLink::query()->create([
        'event_id' => $events[0]->id,
        'tracker_id' => 'jira',
        'work_item_id' => 'APP-42',
        'work_item_url' => 'https://jira.test/browse/APP-42',
        'work_item_title' => 'Shared tracker item',
        'work_item_state' => 'Open',
    ]);

    WorkItemLink::query()->create([
        'event_id' => $events[1]->id,
        'tracker_id' => 'jira',
        'work_item_id' => 'APP-42',
        'work_item_url' => 'https://jira.test/browse/APP-42',
        'work_item_title' => 'Shared tracker item',
        'work_item_state' => 'Open',
    ]);

    WorkItemLink::query()->create([
        'event_id' => $events[2]->id,
        'tracker_id' => 'github',
        'work_item_id' => 'APP-42',
        'work_item_url' => 'https://github.test/octo-org/appsec-scout/issues/42',
        'work_item_title' => 'Different tracker item',
        'work_item_state' => 'Open',
    ]);

    expect($primary->groupedLinks()->pluck('event_id')->all())
        ->toBe([$events[0]->id, $events[1]->id]);
});

// --- SoftwareSystemLink ---

it('creates a system link and attaches members', function () {
    $link = SoftwareSystemLink::factory()->create(['name' => 'My Link']);
    $systems = SoftwareSystem::factory()->count(2)->create();

    $link->members()->attach([
        $systems[0]->id => ['sort_order' => 1],
        $systems[1]->id => ['sort_order' => 2],
    ]);

    expect($link->members()->count())->toBe(2);
});

it('enforces unique member per link (primary key)', function () {
    $link = SoftwareSystemLink::factory()->create();
    $system = SoftwareSystem::factory()->create();

    $link->members()->attach($system->id, ['sort_order' => 1]);

    expect(fn () => $link->members()->attach($system->id, ['sort_order' => 2]))
        ->toThrow(QueryException::class);
});

// --- SecurityContainerLink ---

it('creates a container link and attaches members in sort order', function () {
    $link = SecurityContainerLink::factory()->create(['name' => 'Critical Repositories']);
    $first = SecurityContainer::factory()->create();
    $second = SecurityContainer::factory()->create();

    $link->members()->attach([
        $second->id => ['sort_order' => 2],
        $first->id => ['sort_order' => 1],
    ]);

    expect($link->members()->count())->toBe(2)
        ->and($link->members()->pluck('security_containers.id')->all())->toBe([$first->id, $second->id]);
});

it('enforces unique container member per link (primary key)', function () {
    $link = SecurityContainerLink::factory()->create();
    $container = SecurityContainer::factory()->create();

    $link->members()->attach($container->id, ['sort_order' => 1]);

    expect(fn () => $link->members()->attach($container->id, ['sort_order' => 2]))
        ->toThrow(QueryException::class);
});

it('cascades link memberships when deleting links or containers', function () {
    $link = SecurityContainerLink::factory()->create();
    $container = SecurityContainer::factory()->create();

    $link->members()->attach($container->id, ['sort_order' => 1]);

    $container->delete();

    expect($link->members()->count())->toBe(0)
        ->and(SecurityContainerLink::query()->whereKey($link->id)->exists())->toBeTrue();

    $replacement = SecurityContainer::factory()->create();
    $link->members()->attach($replacement->id, ['sort_order' => 1]);

    $link->delete();

    expect(SecurityContainerLink::query()->whereKey($link->id)->exists())->toBeFalse()
        ->and(SecurityContainer::query()->whereKey($replacement->id)->exists())->toBeTrue();
});

// --- SecurityEvent::scopeForVirtualSystem ---

it('scopes events for virtual system through link members', function () {
    $systemA = SoftwareSystem::factory()->create();
    $systemB = SoftwareSystem::factory()->create();
    $systemC = SoftwareSystem::factory()->create();

    SecurityEvent::factory()->forSystem($systemA)->count(2)->create();
    SecurityEvent::factory()->forSystem($systemB)->count(3)->create();
    SecurityEvent::factory()->forSystem($systemC)->count(1)->create();

    $link = SoftwareSystemLink::factory()->create();
    $link->members()->attach($systemA->id, ['sort_order' => 1]);
    $link->members()->attach($systemB->id, ['sort_order' => 2]);

    expect(SecurityEvent::forVirtualSystem($link->id)->count())->toBe(5);
});
