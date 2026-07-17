<?php

use App\Filament\Widgets\OpenAlertsBySystemChartWidget;
use App\Filament\Widgets\OpenAlertsBySystemTableWidget;
use App\Filament\Widgets\OpenLocalFindingsBySystemTableWidget;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function systemBreakdownReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

it('counts only open alerts per system, ordered by count descending', function () {
    $alpha = SoftwareSystem::factory()->create(['name' => 'Alpha']);
    $beta = SoftwareSystem::factory()->create(['name' => 'Beta']);

    SecurityEvent::factory()->forSystem($alpha)->create(['state' => EventState::Open]);
    SecurityEvent::factory()->forSystem($alpha)->create(['state' => EventState::Open]);
    SecurityEvent::factory()->forSystem($alpha)->create(['state' => EventState::Resolved]);
    SecurityEvent::factory()->forSystem($beta)->create(['state' => EventState::Open]);

    $rows = AlertBreakdownData::openBySystemBreakdown();
    $byKey = collect($rows)->keyBy('key');

    expect($byKey[(string) $alpha->id]['count'])->toBe(2)
        ->and($byKey[(string) $beta->id]['count'])->toBe(1)
        ->and($rows[0]['key'])->toBe((string) $alpha->id);
});

it('caps systems at the top 15 and aggregates the rest into an Others row', function () {
    for ($i = 0; $i < 16; $i++) {
        $system = SoftwareSystem::factory()->create(['name' => "System {$i}"]);
        SecurityEvent::factory()->forSystem($system)->create(['state' => EventState::Open]);
    }

    $rows = AlertBreakdownData::openBySystemBreakdown();
    $others = collect($rows)->firstWhere('key', '__others__');

    expect($others)->not->toBeNull()
        ->and($others['count'])->toBe(1)
        ->and($others['label'])->toBe('Others (1 systems)');
});

it('builds system row urls with open-state filters and no url for unassigned or others', function () {
    $user = systemBreakdownReader();
    $system = SoftwareSystem::factory()->create(['name' => 'Alpha']);
    SecurityEvent::factory()->forSystem($system)->create(['state' => EventState::Open]);

    $widget = Livewire::actingAs($user)->test(OpenAlertsBySystemTableWidget::class)->instance();
    $rowUrl = (new ReflectionMethod($widget, 'rowUrl'));
    $rowUrl->setAccessible(true);

    $linked = $rowUrl->invoke($widget, ['key' => (string) $system->id, 'label' => 'Alpha', 'count' => 1, 'color' => 'info']);

    expect($linked)->toContain('system_scope')
        ->toContain('state')
        ->and($rowUrl->invoke($widget, ['key' => '', 'label' => 'Unassigned', 'count' => 1, 'color' => 'gray']))->toBeNull()
        ->and($rowUrl->invoke($widget, ['key' => '__others__', 'label' => 'Others', 'count' => 1, 'color' => 'gray']))->toBeNull();
});

it('counts only open local findings per system with an unassigned bucket', function () {
    $alpha = SoftwareSystem::factory()->create(['name' => 'Alpha']);
    $container = SecurityContainer::factory()->create();

    $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r1', 'title' => 'a', 'file_path' => 'a', 'status' => EventState::Open, 'software_system_id' => $alpha->id]);
    $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r2', 'title' => 'b', 'file_path' => 'b', 'status' => EventState::Resolved, 'software_system_id' => $alpha->id]);
    $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r3', 'title' => 'c', 'file_path' => 'c', 'status' => EventState::Open, 'software_system_id' => null]);

    $byKey = collect(LocalFindingBreakdownData::openBySystemBreakdown())->keyBy('key');

    expect($byKey[(string) $alpha->id]['count'])->toBe(1)
        ->and($byKey['']['count'])->toBe(1)
        ->and($byKey['']['label'])->toBe('Unassigned');
});

it('renders the open by system pair and hides it without alerts.view', function () {
    $user = systemBreakdownReader();
    $system = SoftwareSystem::factory()->create(['name' => 'Alpha']);
    SecurityEvent::factory()->forSystem($system)->create(['state' => EventState::Open]);

    Livewire::actingAs($user)
        ->test(OpenAlertsBySystemChartWidget::class)
        ->assertOk()
        ->assertSee('Open Alerts by Software System');

    $noAccess = User::factory()->create();
    $noAccess->syncRoles([]);
    $this->actingAs($noAccess);

    expect(OpenAlertsBySystemTableWidget::canView())->toBeFalse()
        ->and(OpenLocalFindingsBySystemTableWidget::canView())->toBeFalse();
});
