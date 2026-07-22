<?php

use App\Filament\Resources\SecurityEventResource;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\Models\WorkItemLink;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('generates a filtered index URL for severity', function () {
    $url = SecurityEventResource::filteredIndexUrl(['severity' => ['critical']]);

    expect($url)->toContain('tableFilters')
        ->toContain('severity')
        ->toContain('critical');
});

it('generates a filtered index URL for state', function () {
    $url = SecurityEventResource::filteredIndexUrl(['state' => ['resolved']]);

    expect($url)->toContain('tableFilters')
        ->toContain('state')
        ->toContain('resolved');
});

it('generates a filtered index URL for multiple states', function () {
    $url = SecurityEventResource::filteredIndexUrl([
        'state' => ['open', 'in_progress', 'acknowledged'],
    ]);

    expect($url)->toContain('open')
        ->toContain('in_progress')
        ->toContain('acknowledged');
});

it('returns a plain index URL when no filters are given', function () {
    $url = SecurityEventResource::filteredIndexUrl([]);
    $indexUrl = SecurityEventResource::getUrl('index');

    expect($url)->toBe($indexUrl);
});

it('alert list page is accessible to a user with alerts.view', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('index'))
        ->assertOk();
});

it('alert list shows type and tracker columns and hides row actions for reader', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    SecurityEvent::factory()->create([
        'severity' => EventSeverity::Critical,
        'state' => EventState::Open,
        'title' => 'Test alert for column check',
    ]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Test alert for column check');
});

it('alert list shows tracker badge for events with work item links', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    $event = SecurityEvent::factory()->create([
        'state' => EventState::Open,
        'severity' => EventSeverity::High,
        'title' => 'Alert with work item',
    ]);

    WorkItemLink::query()->create([
        'event_id' => $event->id,
        'tracker_id' => 'jira',
        'work_item_id' => 'PROJ-42',
        'work_item_title' => 'Fix it',
        'work_item_state' => 'In Progress',
        'work_item_url' => null,
        'created_by_user_id' => null,
        'synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('index'))
        ->assertOk()
        ->assertSee('In Progress');
});

it('alert list shows software system name in system column', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    $system = SoftwareSystem::factory()->create(['name' => 'My Test Application']);
    SecurityEvent::factory()->forSystem($system)->create(['state' => EventState::Open, 'severity' => EventSeverity::High]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('index'))
        ->assertOk()
        ->assertSee('My Test Application');
});

it('alert list shows container name in container column', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    $container = SecurityContainer::factory()->create(['name' => 'api-gateway-repo']);
    SecurityEvent::factory()->forContainer($container)->create(['state' => EventState::Open, 'severity' => EventSeverity::High]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('index'))
        ->assertOk()
        ->assertSee('api-gateway-repo');
});

it('alert list shows the asset name reached via the event system', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    $asset = SoftwareAsset::factory()->create(['name' => 'Payments Platform']);
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id, 'name' => 'payments-service']);
    SecurityEvent::factory()->forSystem($system)->create(['state' => EventState::Open, 'severity' => EventSeverity::High]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Payments Platform');
});

it('dashboard stats widget URLs contain correct filter parameters', function () {
    $openUrl = SecurityEventResource::filteredIndexUrl([
        'state' => [EventState::Open->value, EventState::InProgress->value, EventState::Acknowledged->value],
    ]);

    $resolvedUrl = SecurityEventResource::filteredIndexUrl(['state' => [EventState::Resolved->value]]);
    $criticalUrl = SecurityEventResource::filteredIndexUrl(['severity' => ['critical']]);

    expect($openUrl)->toContain('open')
        ->toContain('in_progress');

    expect($resolvedUrl)->toContain('resolved');
    expect($criticalUrl)->toContain('critical');
});
