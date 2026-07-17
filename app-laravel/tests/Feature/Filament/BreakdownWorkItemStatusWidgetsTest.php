<?php

use App\Filament\Widgets\AlertsByWorkItemStatusTableWidget;
use App\Filament\Widgets\LocalFindingsByWorkItemStatusTableWidget;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WorkItemLink;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function workItemReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

function linkAlert(SecurityEvent $event, ?string $state, string $suffix): void
{
    WorkItemLink::query()->create([
        'event_id' => $event->id,
        'tracker_id' => 'jira',
        'work_item_id' => "WI-{$event->id}-{$suffix}",
        'work_item_title' => 'Item',
        'work_item_state' => $state,
        'work_item_url' => null,
        'created_by_user_id' => null,
        'synced_at' => now(),
    ]);
}

it('counts alerts once per distinct work item status with unknown and no-work-item buckets', function () {
    $a = SecurityEvent::factory()->create();
    linkAlert($a, 'To Do', 'a');
    linkAlert($a, 'Done', 'b');

    $b = SecurityEvent::factory()->create();
    linkAlert($b, 'Done', 'a');
    linkAlert($b, 'Done', 'b');

    $c = SecurityEvent::factory()->create();
    linkAlert($c, null, 'a');

    SecurityEvent::factory()->create();

    $rows = collect(AlertBreakdownData::workItemStatusBreakdown())->keyBy('key');

    expect($rows['To Do']['count'])->toBe(1)
        ->and($rows['Done']['count'])->toBe(2)
        ->and($rows['__none__']['count'])->toBe(1)
        ->and($rows['__no_work_item__']['count'])->toBe(1);
});

it('renders the alerts work item status table with the non-additivity note and filtered links', function () {
    $user = workItemReader();

    $a = SecurityEvent::factory()->create();
    linkAlert($a, 'Done', 'a');
    $c = SecurityEvent::factory()->create();
    linkAlert($c, null, 'a');
    SecurityEvent::factory()->create();

    Livewire::actingAs($user)
        ->test(AlertsByWorkItemStatusTableWidget::class)
        ->assertOk()
        ->assertSee('Alerts by Work Item status')
        ->assertSee('Done')
        ->assertSee('Unknown')
        ->assertSee('No work item')
        ->assertSee('counted once per distinct')
        ->assertSee('work_item_state')
        ->assertSee('__none__')
        ->assertSee('has_work_item');
});

it('counts local findings once per distinct work item status with unknown and no-work-item buckets', function () {
    $container = SecurityContainer::factory()->create();

    $a = $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r1', 'title' => 'a', 'file_path' => 'a']);
    $a->workItemLinks()->create(['tracker_id' => 'github', 'work_item_id' => 'x#1', 'work_item_state' => 'To Do', 'created_at' => now(), 'synced_at' => now()]);
    $a->workItemLinks()->create(['tracker_id' => 'github', 'work_item_id' => 'x#2', 'work_item_state' => 'Done', 'created_at' => now(), 'synced_at' => now()]);

    $b = $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r2', 'title' => 'b', 'file_path' => 'b']);
    $b->workItemLinks()->create(['tracker_id' => 'github', 'work_item_id' => 'y#1', 'work_item_state' => 'Done', 'created_at' => now(), 'synced_at' => now()]);
    $b->workItemLinks()->create(['tracker_id' => 'github', 'work_item_id' => 'y#2', 'work_item_state' => 'Done', 'created_at' => now(), 'synced_at' => now()]);

    $c = $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r3', 'title' => 'c', 'file_path' => 'c']);
    $c->workItemLinks()->create(['tracker_id' => 'github', 'work_item_id' => 'z#1', 'work_item_state' => null, 'created_at' => now(), 'synced_at' => now()]);

    $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r4', 'title' => 'd', 'file_path' => 'd']);

    $rows = collect(LocalFindingBreakdownData::workItemStatusBreakdown())->keyBy('key');

    expect($rows['To Do']['count'])->toBe(1)
        ->and($rows['Done']['count'])->toBe(2)
        ->and($rows['__none__']['count'])->toBe(1)
        ->and($rows['__no_work_item__']['count'])->toBe(1);
});

it('renders the local findings work item status table with filtered links', function () {
    $user = workItemReader();
    $container = SecurityContainer::factory()->create();
    $a = $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r1', 'title' => 'a', 'file_path' => 'a']);
    $a->workItemLinks()->create(['tracker_id' => 'github', 'work_item_id' => 'x#1', 'work_item_state' => 'Done', 'created_at' => now(), 'synced_at' => now()]);
    $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r2', 'title' => 'b', 'file_path' => 'b']);

    Livewire::actingAs($user)
        ->test(LocalFindingsByWorkItemStatusTableWidget::class)
        ->assertOk()
        ->assertSee('Local Findings by Work Item status')
        ->assertSee('Done')
        ->assertSee('No work item')
        ->assertSee('work_item_state')
        ->assertSee('has_work_item');
});

it('hides the work item status widgets from users without alerts.view', function () {
    $user = User::factory()->create();
    $user->syncRoles([]);

    $this->actingAs($user);

    expect(AlertsByWorkItemStatusTableWidget::canView())->toBeFalse()
        ->and(LocalFindingsByWorkItemStatusTableWidget::canView())->toBeFalse();
});
