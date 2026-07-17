<?php

use App\Filament\Resources\LocalFindingResource;
use App\Filament\Resources\LocalFindingResource\Support\LocalFindingTableQuery;
use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\WorkItemLink;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function makeAlertWithWorkItemStates(array $states): SecurityEvent
{
    $event = SecurityEvent::factory()->create();

    foreach ($states as $index => $state) {
        WorkItemLink::query()->create([
            'event_id' => $event->id,
            'tracker_id' => 'jira',
            'work_item_id' => "WI-{$event->id}-{$index}",
            'work_item_title' => 'Item',
            'work_item_state' => $state,
            'work_item_url' => null,
            'created_by_user_id' => null,
            'synced_at' => now(),
        ]);
    }

    return $event;
}

it('filters alerts by a single work item status', function () {
    $todo = makeAlertWithWorkItemStates(['To Do']);
    $done = makeAlertWithWorkItemStates(['Done']);
    makeAlertWithWorkItemStates([]);

    $ids = SecurityEventTableQuery::applyWorkItemStates(SecurityEvent::query(), ['To Do'])->pluck('id')->all();

    expect($ids)->toBe([$todo->id])
        ->and($ids)->not->toContain($done->id);
});

it('matches an alert with links in two statuses under either bucket', function () {
    $both = makeAlertWithWorkItemStates(['To Do', 'Done']);

    expect(SecurityEventTableQuery::applyWorkItemStates(SecurityEvent::query(), ['To Do'])->pluck('id')->all())->toBe([$both->id])
        ->and(SecurityEventTableQuery::applyWorkItemStates(SecurityEvent::query(), ['Done'])->pluck('id')->all())->toBe([$both->id]);
});

it('matches alerts with null work item state via the Unknown sentinel', function () {
    $unknown = makeAlertWithWorkItemStates([null]);
    makeAlertWithWorkItemStates(['Done']);
    makeAlertWithWorkItemStates([]);

    $ids = SecurityEventTableQuery::applyWorkItemStates(SecurityEvent::query(), ['__none__'])->pluck('id')->all();

    expect($ids)->toBe([$unknown->id]);
});

it('excludes alerts without any work item link from the status filter', function () {
    makeAlertWithWorkItemStates(['Done']);
    makeAlertWithWorkItemStates([]);

    expect(SecurityEventTableQuery::applyWorkItemStates(SecurityEvent::query(), ['Done', '__none__'])->count())->toBe(1)
        ->and(SecurityEventTableQuery::applyWorkItemStates(SecurityEvent::query(), [])->count())->toBe(2);
});

function makeFindingWithWorkItemStates(SecurityContainer $container, string $ruleId, array $states): LocalFinding
{
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => $ruleId,
        'title' => $ruleId,
        'file_path' => 'a',
    ]);

    foreach ($states as $index => $state) {
        $finding->workItemLinks()->create([
            'tracker_id' => 'github',
            'work_item_id' => "{$ruleId}#{$index}",
            'work_item_state' => $state,
            'created_at' => now(),
            'synced_at' => now(),
        ]);
    }

    return $finding;
}

it('filters local findings by work item status including the Unknown sentinel', function () {
    $container = SecurityContainer::factory()->create();

    $done = makeFindingWithWorkItemStates($container, 'r1', ['Done']);
    $both = makeFindingWithWorkItemStates($container, 'r2', ['Done', 'To Do']);
    $unknown = makeFindingWithWorkItemStates($container, 'r3', [null]);
    makeFindingWithWorkItemStates($container, 'r4', []);

    expect(LocalFindingTableQuery::applyWorkItemStates(LocalFinding::query(), ['Done'])->pluck('id')->all())->toBe([$done->id, $both->id])
        ->and(LocalFindingTableQuery::applyWorkItemStates(LocalFinding::query(), ['To Do'])->pluck('id')->all())->toBe([$both->id])
        ->and(LocalFindingTableQuery::applyWorkItemStates(LocalFinding::query(), ['__none__'])->pluck('id')->all())->toBe([$unknown->id])
        ->and(LocalFindingTableQuery::applyWorkItemStates(LocalFinding::query(), [])->count())->toBe(4);
});

it('builds a filtered local findings URL with multi-select filter parameters', function () {
    $url = LocalFindingResource::filteredIndexUrl(['work_item_state' => ['Done']]);

    expect($url)->toContain('tableFilters')
        ->toContain('work_item_state')
        ->toContain('Done');
});

it('builds a filtered local findings URL for multiple values', function () {
    $url = LocalFindingResource::filteredIndexUrl(['status' => ['open', 'acknowledged']]);

    expect($url)->toContain('open')->toContain('acknowledged');
});

it('returns a plain local findings index URL when no filters are given', function () {
    expect(LocalFindingResource::filteredIndexUrl([]))->toBe(LocalFindingResource::getUrl('index'));
});

it('matches the alerts filtered URL shape on the local findings helper', function () {
    $alertUrl = SecurityEventResource::filteredIndexUrl(['severity' => ['critical']]);
    $findingUrl = LocalFindingResource::filteredIndexUrl(['severity' => ['critical']]);

    expect($findingUrl)->toContain('tableFilters')
        ->and($alertUrl)->toContain('tableFilters');
});
