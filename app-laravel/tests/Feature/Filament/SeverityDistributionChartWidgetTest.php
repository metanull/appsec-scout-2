<?php

use App\Filament\Widgets\SeverityDistributionChartWidget;
use App\Filament\Widgets\Support\DashboardData;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    DashboardData::flushCache();
});

afterEach(function () {
    DashboardData::flushCache();
});

function severityChartReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

it('shows a no-data heading when there are no recorded severities', function () {
    Livewire::actingAs(severityChartReader())
        ->test(SeverityDistributionChartWidget::class)
        ->assertSee('Open Alerts by Severity — no alerts recorded')
        ->assertHasNoErrors();
});

it('shows the plain heading once at least one open severity is recorded', function () {
    SecurityEvent::factory()->create(['severity' => EventSeverity::Critical, 'state' => EventState::Open]);

    Livewire::actingAs(severityChartReader())
        ->test(SeverityDistributionChartWidget::class)
        ->assertSee('Open Alerts by Severity')
        ->assertDontSee('Open Alerts by Severity — no alerts recorded')
        ->assertHasNoErrors();
});

it('shows the no-data heading when only non-open severities are recorded', function () {
    SecurityEvent::factory()->create(['severity' => EventSeverity::Critical, 'state' => EventState::Resolved]);

    Livewire::actingAs(severityChartReader())
        ->test(SeverityDistributionChartWidget::class)
        ->assertSee('Open Alerts by Severity — no alerts recorded')
        ->assertHasNoErrors();
});
