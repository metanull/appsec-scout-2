<?php

use App\Filament\Resources\FailedJobResource;
use App\Filament\Widgets\RecentFailedJobsTableWidget;
use App\Models\FailedJob;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function actingAdminForRecentFailedJobsWidget(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);

    return $user;
}

function makeFailedJobRecord(array $overrides = []): FailedJob
{
    return FailedJob::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SomeJob']),
        'exception' => 'Exception: something failed',
        'failed_at' => now(),
    ], $overrides));
}

it('renders the recent failed jobs widget with a view all action linking to the failed job resource', function () {
    Livewire::actingAs(actingAdminForRecentFailedJobsWidget())
        ->test(RecentFailedJobsTableWidget::class)
        ->assertSee('Recent failed jobs')
        ->assertHasNoErrors()
        ->assertTableActionExists('viewAll')
        ->assertTableActionHasUrl('viewAll', FailedJobResource::getUrl('index'));
});

it('only shows failed jobs from the last 24 hours', function () {
    $recent = makeFailedJobRecord(['failed_at' => now()->subHours(2)]);
    $stale = makeFailedJobRecord(['failed_at' => now()->subDays(3)]);

    Livewire::actingAs(actingAdminForRecentFailedJobsWidget())
        ->test(RecentFailedJobsTableWidget::class)
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$stale]);
});

it('shows an accurate empty state when there are no failed jobs in the last 24 hours', function () {
    makeFailedJobRecord(['failed_at' => now()->subDays(3)]);

    Livewire::actingAs(actingAdminForRecentFailedJobsWidget())
        ->test(RecentFailedJobsTableWidget::class)
        ->assertSee('No failed jobs in the last 24 hours.');
});
