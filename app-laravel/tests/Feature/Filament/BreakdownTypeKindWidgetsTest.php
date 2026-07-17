<?php

use App\Filament\Widgets\OpenAlertsByTypeChartWidget;
use App\Filament\Widgets\OpenAlertsByTypeTableWidget;
use App\Filament\Widgets\OpenLocalFindingsByKindTableWidget;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function typeKindReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

it('counts only open alerts per type and omits zero buckets', function () {
    SecurityEvent::factory()->create(['type' => EventType::Vulnerability, 'state' => EventState::Open]);
    SecurityEvent::factory()->create(['type' => EventType::Vulnerability, 'state' => EventState::Open]);
    SecurityEvent::factory()->create(['type' => EventType::Secret, 'state' => EventState::Open]);
    SecurityEvent::factory()->create(['type' => EventType::Secret, 'state' => EventState::Resolved]);

    $rows = AlertBreakdownData::openByTypeBreakdown();
    $byKey = collect($rows)->keyBy('key');

    expect($byKey['vulnerability']['count'])->toBe(2)
        ->and($byKey['vulnerability']['label'])->toBe('Vulnerability')
        ->and($byKey['secret']['count'])->toBe(1)
        ->and($byKey->has('dependency'))->toBeFalse();
});

it('counts only open local findings per kind with badge colors', function () {
    $container = SecurityContainer::factory()->create();
    $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r1', 'title' => 'a', 'file_path' => 'a', 'status' => EventState::Open]);
    $container->localFindings()->create(['kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r2', 'title' => 'b', 'file_path' => 'b', 'status' => EventState::Open]);
    $container->localFindings()->create(['kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r3', 'title' => 'c', 'file_path' => 'c', 'status' => EventState::Resolved]);

    $byKey = collect(LocalFindingBreakdownData::openByKindBreakdown())->keyBy('key');

    expect($byKey[LocalFinding::KIND_SECRET]['count'])->toBe(1)
        ->and($byKey[LocalFinding::KIND_SECRET]['color'])->toBe('danger')
        ->and($byKey[LocalFinding::KIND_VULNERABILITY]['count'])->toBe(1)
        ->and($byKey[LocalFinding::KIND_VULNERABILITY]['color'])->toBe('warning');
});

it('builds type and kind row urls with open-state filters', function () {
    $user = typeKindReader();
    SecurityEvent::factory()->create(['type' => EventType::Vulnerability, 'state' => EventState::Open]);

    $alertWidget = Livewire::actingAs($user)->test(OpenAlertsByTypeTableWidget::class)->instance();
    $alertRowUrl = (new ReflectionMethod($alertWidget, 'rowUrl'));
    $alertRowUrl->setAccessible(true);

    expect($alertRowUrl->invoke($alertWidget, ['key' => 'vulnerability', 'label' => 'Vulnerability', 'count' => 1, 'color' => 'danger']))
        ->toContain('type')
        ->toContain('state');

    $findingWidget = Livewire::actingAs($user)->test(OpenLocalFindingsByKindTableWidget::class)->instance();
    $findingRowUrl = (new ReflectionMethod($findingWidget, 'rowUrl'));
    $findingRowUrl->setAccessible(true);

    expect($findingRowUrl->invoke($findingWidget, ['key' => LocalFinding::KIND_SECRET, 'label' => 'Secret', 'count' => 1, 'color' => 'danger']))
        ->toContain('kind')
        ->toContain('status');
});

it('renders the type and kind widgets and hides them without alerts.view', function () {
    $user = typeKindReader();
    SecurityEvent::factory()->create(['type' => EventType::Vulnerability, 'state' => EventState::Open]);

    Livewire::actingAs($user)
        ->test(OpenAlertsByTypeChartWidget::class)
        ->assertOk()
        ->assertSee('Open Alerts by Type');

    $noAccess = User::factory()->create();
    $noAccess->syncRoles([]);
    $this->actingAs($noAccess);

    expect(OpenAlertsByTypeTableWidget::canView())->toBeFalse()
        ->and(OpenLocalFindingsByKindTableWidget::canView())->toBeFalse();
});
