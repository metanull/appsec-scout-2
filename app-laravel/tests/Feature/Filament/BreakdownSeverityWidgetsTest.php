<?php

use App\Filament\Widgets\OpenAlertsBySeverityChartWidget;
use App\Filament\Widgets\OpenAlertsBySeverityTableWidget;
use App\Filament\Widgets\OpenLocalFindingsBySeverityTableWidget;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function severityBreakdownReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

it('counts only open alerts per severity with agreeing badge colors', function () {
    SecurityEvent::factory()->create(['severity' => EventSeverity::Critical, 'state' => EventState::Open]);
    SecurityEvent::factory()->create(['severity' => EventSeverity::Critical, 'state' => EventState::Open]);
    SecurityEvent::factory()->create(['severity' => EventSeverity::High, 'state' => EventState::Open]);
    SecurityEvent::factory()->create(['severity' => EventSeverity::Critical, 'state' => EventState::Resolved]);

    $byKey = collect(AlertBreakdownData::openBySeverityBreakdown())->keyBy('key');

    expect($byKey['critical']['count'])->toBe(2)
        ->and($byKey['critical']['color'])->toBe('danger')
        ->and($byKey['high']['count'])->toBe(1);
});

it('counts local findings by override-aware effective severity with an unknown bucket', function () {
    $container = SecurityContainer::factory()->create();

    $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r1', 'title' => 'a', 'file_path' => 'a',
        'status' => EventState::Open, 'severity' => 'HIGH', 'overridden_severity' => EventSeverity::Critical,
    ]);
    $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r2', 'title' => 'b', 'file_path' => 'b',
        'status' => EventState::Open, 'severity' => 'UNKNOWN',
    ]);
    $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r3', 'title' => 'c', 'file_path' => 'c',
        'status' => EventState::Resolved, 'severity' => 'CRITICAL',
    ]);

    $byKey = collect(LocalFindingBreakdownData::openBySeverityBreakdown())->keyBy('key');

    expect($byKey['critical']['count'])->toBe(1)
        ->and($byKey->has('high'))->toBeFalse()
        ->and($byKey['unknown']['count'])->toBe(1)
        ->and($byKey['unknown']['color'])->toBe('gray');
});

it('builds severity row urls including the unknown option', function () {
    $user = severityBreakdownReader();
    $container = SecurityContainer::factory()->create();
    $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r1', 'title' => 'a', 'file_path' => 'a',
        'status' => EventState::Open, 'severity' => 'UNKNOWN',
    ]);

    $alertWidget = Livewire::actingAs($user)->test(OpenAlertsBySeverityTableWidget::class)->instance();
    $alertRowUrl = (new ReflectionMethod($alertWidget, 'rowUrl'));
    $alertRowUrl->setAccessible(true);

    expect($alertRowUrl->invoke($alertWidget, ['key' => 'critical', 'label' => 'Critical', 'count' => 1, 'color' => 'danger']))
        ->toContain('severity')
        ->toContain('state');

    $findingWidget = Livewire::actingAs($user)->test(OpenLocalFindingsBySeverityTableWidget::class)->instance();
    $findingRowUrl = (new ReflectionMethod($findingWidget, 'rowUrl'));
    $findingRowUrl->setAccessible(true);

    expect($findingRowUrl->invoke($findingWidget, ['key' => 'unknown', 'label' => 'Unknown', 'count' => 1, 'color' => 'gray']))
        ->toContain('severity')
        ->toContain('status')
        ->toContain('unknown');
});

it('renders the severity widgets and hides them without alerts.view', function () {
    $user = severityBreakdownReader();
    SecurityEvent::factory()->create(['severity' => EventSeverity::Critical, 'state' => EventState::Open]);

    Livewire::actingAs($user)
        ->test(OpenAlertsBySeverityChartWidget::class)
        ->assertOk()
        ->assertSee('Open Alerts by Severity');

    $noAccess = User::factory()->create();
    $noAccess->syncRoles([]);
    $this->actingAs($noAccess);

    expect(OpenAlertsBySeverityTableWidget::canView())->toBeFalse()
        ->and(OpenLocalFindingsBySeverityTableWidget::canView())->toBeFalse();
});
