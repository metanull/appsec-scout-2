<?php

use App\Filament\Widgets\OpenAlertsBySourceWidget;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('renders open alerts by source widget without closure resolution errors', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'state' => EventState::Open,
    ]);

    Livewire::actingAs($user)
        ->test(OpenAlertsBySourceWidget::class)
        ->assertSee('Open alerts by source')
        ->assertSee('azdo');
});
