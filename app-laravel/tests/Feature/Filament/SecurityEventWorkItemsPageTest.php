<?php

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SecurityEventResource\RelationManagers\WorkItemLinksRelationManager;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WorkItemLink;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('renders linked work items on the alert detail page', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create([
        'title' => 'Alert with linked work item',
    ]);

    WorkItemLink::query()->create([
        'event_id' => $event->id,
        'tracker_id' => 'github',
        'work_item_id' => 'octo-org/appsec-scout#101',
        'work_item_title' => 'Grouped secret findings',
        'work_item_state' => 'Open',
        'work_item_url' => 'https://github.test/octo-org/appsec-scout/issues/101',
        'created_by_user_id' => $user->id,
        'created_at' => now(),
        'synced_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(WorkItemLinksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => SecurityEventResource\Pages\ViewSecurityEvent::class,
        ])
        ->call('loadTable')
        ->assertSee('Grouped secret findings')
        ->assertSee('octo-org/appsec-scout#101')
        ->assertSee('Unlink');
});

it('shows "This alert only" when the work item has no sibling alerts', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create();

    WorkItemLink::query()->create([
        'event_id' => $event->id,
        'tracker_id' => 'jira',
        'work_item_id' => 'SOLO-1',
        'work_item_title' => 'Standalone issue',
        'work_item_state' => 'Open',
        'work_item_url' => null,
        'created_by_user_id' => null,
        'synced_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(WorkItemLinksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => SecurityEventResource\Pages\ViewSecurityEvent::class,
        ])
        ->call('loadTable')
        ->assertSee('1 alert');
});

it('shows sibling count and view link when the work item is shared', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $eventA = SecurityEvent::factory()->create();
    $eventB = SecurityEvent::factory()->create();
    $eventC = SecurityEvent::factory()->create();

    foreach ([$eventA, $eventB, $eventC] as $ev) {
        WorkItemLink::query()->create([
            'event_id' => $ev->id,
            'tracker_id' => 'jira',
            'work_item_id' => 'GROUP-5',
            'work_item_title' => 'Shared work item',
            'work_item_state' => 'In Progress',
            'work_item_url' => null,
            'created_by_user_id' => null,
            'synced_at' => now(),
        ]);
    }

    $livewire = Livewire::actingAs($user)
        ->test(WorkItemLinksRelationManager::class, [
            'ownerRecord' => $eventA,
            'pageClass' => SecurityEventResource\Pages\ViewSecurityEvent::class,
        ])
        ->call('loadTable');

    $livewire->assertSee('3 alerts');

    // The work_item filter URL should be present
    expect($livewire->html())->toContain('work_item');
});
