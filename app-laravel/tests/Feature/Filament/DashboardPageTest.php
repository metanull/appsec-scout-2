<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AlertsByStateChartWidget;
use App\Filament\Widgets\AlertsByStateTableWidget;
use App\Filament\Widgets\LocalFindingsByStateChartWidget;
use App\Filament\Widgets\LocalFindingsByStateTableWidget;
use App\Filament\Widgets\OpenLocalFindingsBySeverityTableWidget;
use App\Filament\Widgets\Support\DashboardData;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function dashboardReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

it('renders the dashboard for a user with alerts.view', function () {
    $user = dashboardReader();
    SecurityEvent::factory()->create(['state' => EventState::Open]);

    // Widgets lazy-load over Livewire, so the page GET only needs to render
    // without error; individual widget contents are covered per widget.
    $this->actingAs($user)
        ->get(Dashboard::getUrl())
        ->assertOk();
});

it('pins the widget layout to the agreed grouped order', function () {
    $widgets = (new Dashboard)->getWidgets();

    expect($widgets)->toHaveCount(28)
        ->and(array_slice($widgets, 0, 4))->toBe([
            AlertsByStateTableWidget::class,
            AlertsByStateChartWidget::class,
            LocalFindingsByStateChartWidget::class,
            LocalFindingsByStateTableWidget::class,
        ])
        ->and($widgets[21])->toBe(OpenLocalFindingsBySeverityTableWidget::class)
        ->and((new Dashboard)->getColumns())->toBe(3);
});

it('renders each alerts pair before its local findings pair, pie alternating', function () {
    $widgets = (new Dashboard)->getWidgets();

    // Alerts by State: table then pie (pie right); Local Findings by State: pie then table (pie left)
    expect($widgets[0])->toBe(AlertsByStateTableWidget::class)
        ->and($widgets[1])->toBe(AlertsByStateChartWidget::class)
        ->and($widgets[2])->toBe(LocalFindingsByStateChartWidget::class)
        ->and($widgets[3])->toBe(LocalFindingsByStateTableWidget::class);
});

it('no longer references the removed legacy widgets', function () {
    expect(class_exists('App\Filament\Widgets\SecurityOverviewStatsWidget'))->toBeFalse()
        ->and(class_exists('App\Filament\Widgets\SeverityDistributionChartWidget'))->toBeFalse()
        ->and(class_exists('App\Filament\Widgets\TypeDistributionChartWidget'))->toBeFalse()
        ->and(class_exists('App\Filament\Widgets\OpenAlertsBySourceWidget'))->toBeFalse();
});

it('no longer exposes the removed dashboard data aggregates', function () {
    expect(method_exists(DashboardData::class, 'stats'))->toBeFalse()
        ->and(method_exists(DashboardData::class, 'severityChart'))->toBeFalse()
        ->and(method_exists(DashboardData::class, 'typeChart'))->toBeFalse()
        ->and(method_exists(DashboardData::class, 'openAlertsBySourceAndWorkItemState'))->toBeFalse()
        ->and(method_exists(DashboardData::class, 'recentSyncRuns'))->toBeTrue()
        ->and(method_exists(DashboardData::class, 'formatCounts'))->toBeTrue();
});
