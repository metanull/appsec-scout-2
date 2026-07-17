<?php

use App\Filament\Widgets\AlertsByStateChartWidget;
use App\Filament\Widgets\AlertsByStateTableWidget;
use App\Filament\Widgets\LocalFindingsByStateChartWidget;
use App\Filament\Widgets\LocalFindingsByStateTableWidget;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function stateBreakdownReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

function makeFinding(SecurityContainer $container, string $ruleId, EventState $status): LocalFinding
{
    return $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => $ruleId,
        'title' => $ruleId,
        'file_path' => 'a',
        'status' => $status,
    ]);
}

it('buckets alert states into open, in progress, and closed', function () {
    SecurityEvent::factory()->create(['state' => EventState::Open]);
    SecurityEvent::factory()->create(['state' => EventState::InProgress]);
    SecurityEvent::factory()->create(['state' => EventState::Acknowledged]);
    SecurityEvent::factory()->create(['state' => EventState::Resolved]);
    SecurityEvent::factory()->create(['state' => EventState::Dismissed]);

    $byKey = collect(AlertBreakdownData::stateBreakdown())->keyBy('key');

    expect($byKey['open']['count'])->toBe(1)
        ->and($byKey['in_progress']['count'])->toBe(1)
        ->and($byKey['closed']['count'])->toBe(3);
});

it('caches the alert state breakdown under its key', function () {
    Cache::forget(AlertBreakdownData::STATE_CACHE_KEY);
    SecurityEvent::factory()->create(['state' => EventState::Open]);

    AlertBreakdownData::stateBreakdown();

    expect(Cache::has(AlertBreakdownData::STATE_CACHE_KEY))->toBeTrue();
});

it('buckets local finding statuses into open, in progress, and closed', function () {
    $container = SecurityContainer::factory()->create();
    makeFinding($container, 'r1', EventState::Open);
    makeFinding($container, 'r2', EventState::InProgress);
    makeFinding($container, 'r3', EventState::Acknowledged);
    makeFinding($container, 'r4', EventState::Resolved);
    makeFinding($container, 'r5', EventState::Dismissed);

    $byKey = collect(LocalFindingBreakdownData::stateBreakdown())->keyBy('key');

    expect($byKey['open']['count'])->toBe(1)
        ->and($byKey['in_progress']['count'])->toBe(1)
        ->and($byKey['closed']['count'])->toBe(3);
});

it('caches the local finding state breakdown under its key', function () {
    Cache::forget(LocalFindingBreakdownData::STATE_CACHE_KEY);

    LocalFindingBreakdownData::stateBreakdown();

    expect(Cache::has(LocalFindingBreakdownData::STATE_CACHE_KEY))->toBeTrue();
});

it('renders the alerts by state pair with counts and filtered row links', function () {
    $user = stateBreakdownReader();
    SecurityEvent::factory()->create(['state' => EventState::Open]);

    Livewire::actingAs($user)
        ->test(AlertsByStateTableWidget::class)
        ->assertOk()
        ->assertSee('Alerts by State')
        ->assertSee('Open')
        ->assertSee('tableFilters');

    Livewire::actingAs($user)
        ->test(AlertsByStateChartWidget::class)
        ->assertOk()
        ->assertSee('Alerts by State');
});

it('renders the local findings by state pair with counts and filtered row links', function () {
    $user = stateBreakdownReader();
    $container = SecurityContainer::factory()->create();
    makeFinding($container, 'r1', EventState::Open);

    Livewire::actingAs($user)
        ->test(LocalFindingsByStateTableWidget::class)
        ->assertOk()
        ->assertSee('Local Findings by State')
        ->assertSee('Open')
        ->assertSee('tableFilters');

    Livewire::actingAs($user)
        ->test(LocalFindingsByStateChartWidget::class)
        ->assertOk()
        ->assertSee('Local Findings by State');
});

it('hides the by state widgets from users without alerts.view', function () {
    $user = User::factory()->create();
    $user->syncRoles([]);

    $this->actingAs($user);

    expect(AlertsByStateTableWidget::canView())->toBeFalse()
        ->and(AlertsByStateChartWidget::canView())->toBeFalse()
        ->and(LocalFindingsByStateTableWidget::canView())->toBeFalse()
        ->and(LocalFindingsByStateChartWidget::canView())->toBeFalse();
});
