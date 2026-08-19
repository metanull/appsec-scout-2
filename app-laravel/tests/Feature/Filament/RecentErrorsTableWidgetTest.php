<?php

use App\Filament\Resources\ErrorLogResource;
use App\Filament\Widgets\RecentErrorsTableWidget;
use App\Models\ErrorLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function actingAdminForRecentErrorsWidget(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);

    return $user;
}

it('renders the recent errors widget with a view all action linking to the error log resource', function () {
    Livewire::actingAs(actingAdminForRecentErrorsWidget())
        ->test(RecentErrorsTableWidget::class)
        ->assertSee('Recent errors')
        ->assertHasNoErrors()
        ->assertTableActionExists('viewAll')
        ->assertTableActionHasUrl('viewAll', ErrorLogResource::getUrl('index'));
});

it('links each row to the error log view page', function () {
    $recent = ErrorLog::create([
        'level' => 'error',
        'channel' => 'sync',
        'message' => 'recent error',
        'occurred_at' => now()->subHours(2),
    ]);

    Livewire::actingAs(actingAdminForRecentErrorsWidget())
        ->test(RecentErrorsTableWidget::class)
        ->assertSee(ErrorLogResource::getUrl('view', ['record' => $recent]), escape: false);
});

it('only shows errors from the last 24 hours', function () {
    $recent = ErrorLog::create([
        'level' => 'error',
        'channel' => 'sync',
        'message' => 'recent error',
        'occurred_at' => now()->subHours(2),
    ]);
    $stale = ErrorLog::create([
        'level' => 'error',
        'channel' => 'sync',
        'message' => 'stale error',
        'occurred_at' => now()->subDays(3),
    ]);

    Livewire::actingAs(actingAdminForRecentErrorsWidget())
        ->test(RecentErrorsTableWidget::class)
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$stale]);
});

it('shows an accurate empty state when there are no errors in the last 24 hours', function () {
    ErrorLog::create([
        'level' => 'error',
        'channel' => 'sync',
        'message' => 'stale error',
        'occurred_at' => now()->subDays(3),
    ]);

    Livewire::actingAs(actingAdminForRecentErrorsWidget())
        ->test(RecentErrorsTableWidget::class)
        ->assertSee('No errors in the last 24 hours');
});
