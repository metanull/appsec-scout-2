<?php

use App\Filament\Widgets\OpenAlertsBySourceBreakdownTableWidget;
use App\Filament\Widgets\OpenAlertsBySourceChartWidget;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function sourceBreakdownReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

it('counts only open alerts per source', function () {
    SecurityEvent::factory()->create(['source_id' => 'azdo', 'state' => EventState::Open]);
    SecurityEvent::factory()->create(['source_id' => 'azdo', 'state' => EventState::Open]);
    SecurityEvent::factory()->create(['source_id' => 'azdo', 'state' => EventState::Resolved]);
    SecurityEvent::factory()->create(['source_id' => 'asoc', 'state' => EventState::Open]);

    $byKey = collect(AlertBreakdownData::openBySourceBreakdown())->keyBy('key');

    expect($byKey['azdo']['count'])->toBe(2)
        ->and($byKey['asoc']['count'])->toBe(1);
});

it('builds source row urls carrying source and open-state filters', function () {
    $user = sourceBreakdownReader();
    SecurityEvent::factory()->create(['source_id' => 'azdo', 'state' => EventState::Open]);

    $widget = Livewire::actingAs($user)->test(OpenAlertsBySourceBreakdownTableWidget::class)->instance();
    $rowUrl = (new ReflectionMethod($widget, 'rowUrl'));
    $rowUrl->setAccessible(true);

    expect($rowUrl->invoke($widget, ['key' => 'azdo', 'label' => 'azdo', 'count' => 1, 'color' => 'info']))
        ->toContain('source_id')
        ->toContain('state');
});

it('renders the open alerts by source pair and hides it without alerts.view', function () {
    $user = sourceBreakdownReader();
    SecurityEvent::factory()->create(['source_id' => 'azdo', 'state' => EventState::Open]);

    Livewire::actingAs($user)
        ->test(OpenAlertsBySourceBreakdownTableWidget::class)
        ->assertOk()
        ->assertSee('Open Alerts by Source')
        ->assertSee('azdo')
        ->assertSee('tableFilters');

    Livewire::actingAs($user)
        ->test(OpenAlertsBySourceChartWidget::class)
        ->assertOk()
        ->assertSee('Open Alerts by Source');

    $noAccess = User::factory()->create();
    $noAccess->syncRoles([]);
    $this->actingAs($noAccess);

    expect(OpenAlertsBySourceBreakdownTableWidget::canView())->toBeFalse()
        ->and(OpenAlertsBySourceChartWidget::canView())->toBeFalse();
});
