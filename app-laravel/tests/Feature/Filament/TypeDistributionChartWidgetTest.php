<?php

use App\Filament\Widgets\Support\DashboardData;
use App\Filament\Widgets\TypeDistributionChartWidget;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
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

function typeChartReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

it('shows a no-data heading when there are no recorded open types', function () {
    Livewire::actingAs(typeChartReader())
        ->test(TypeDistributionChartWidget::class)
        ->assertSee('Open Alerts by Type — no alerts recorded')
        ->assertHasNoErrors();
});

it('shows the plain heading once at least one open type is recorded', function () {
    SecurityEvent::factory()->create(['type' => EventType::Secret, 'state' => EventState::Open]);

    Livewire::actingAs(typeChartReader())
        ->test(TypeDistributionChartWidget::class)
        ->assertSee('Open Alerts by Type')
        ->assertDontSee('Open Alerts by Type — no alerts recorded')
        ->assertHasNoErrors();
});

it('shows the no-data heading when only non-open types are recorded', function () {
    SecurityEvent::factory()->create(['type' => EventType::Secret, 'state' => EventState::Resolved]);

    Livewire::actingAs(typeChartReader())
        ->test(TypeDistributionChartWidget::class)
        ->assertSee('Open Alerts by Type — no alerts recorded')
        ->assertHasNoErrors();
});
