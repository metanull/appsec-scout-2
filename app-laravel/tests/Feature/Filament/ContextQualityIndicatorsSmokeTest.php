<?php

use App\Filament\Resources\SecurityContainerLinkResource;
use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SoftwareSystemResource;
use App\Models\SecurityContainer;
use App\Models\SecurityContainerLink;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('shows context quality sections for reader-visible pages', function () {
    $reader = qualityUser(['Reader']);
    [$system, $container, $event, $containerLink] = seededQualityGraph();

    $this->actingAs($reader)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('Context quality');

    $this->actingAs($reader)
        ->get(SoftwareSystemResource::getUrl('view', ['record' => $system]))
        ->assertOk()
        ->assertSee('Context quality');

    $this->actingAs($reader)
        ->get(SecurityContainerResource::getUrl('view', ['record' => $container]))
        ->assertOk()
        ->assertSee('Context quality');

    $this->actingAs($reader)
        ->get(SecurityContainerLinkResource::getUrl('view', ['record' => $containerLink]))
        ->assertOk()
        ->assertSee('Context quality');
});

/**
 * @return array{SoftwareSystem, SecurityContainer, SecurityEvent, SecurityContainerLink}
 */
function seededQualityGraph(): array
{
    $system = SoftwareSystem::factory()->create(['url' => 'https://example.test/systems/payments']);
    $container = SecurityContainer::factory()->forSystem($system)->create(['url' => 'https://example.test/containers/payments']);
    $event = SecurityEvent::factory()->forContainer($container)->create(['file_path' => 'src/Payments.php']);

    $containerLink = SecurityContainerLink::factory()->create();
    $containerLink->members()->attach($container->id, ['sort_order' => 1]);

    return [$system, $container, $event, $containerLink];
}

function qualityUser(array $roles): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $user->syncRoles($roles);

    return $user;
}
