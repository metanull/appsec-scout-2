<?php

use App\Filament\Resources\ErrorLogResource;
use App\Filament\Widgets\RecentErrorsTableWidget;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('renders the recent errors widget with a view all action linking to the error log resource', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);

    Livewire::actingAs($user)
        ->test(RecentErrorsTableWidget::class)
        ->assertSee('Recent errors')
        ->assertHasNoErrors()
        ->assertTableActionExists('viewAll')
        ->assertTableActionHasUrl('viewAll', ErrorLogResource::getUrl('index'));
});
