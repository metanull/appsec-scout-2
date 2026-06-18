<?php

use App\Filament\Resources\RepositoryProviderResource;
use App\Filament\Resources\SecurityEventResource\Pages\ViewSecurityEvent;
use App\Filament\Resources\Shared\RelationManagers\CuratedLinksRelationManager;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function permissionUser(array $permissions = [], array $roles = []): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    if ($roles !== []) {
        $user->syncRoles($roles);
    }

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

// ---------------------------------------------------------------------------
// RepositoryProviderResource — admin.repository-providers
// ---------------------------------------------------------------------------

it('grants RepositoryProvider access to a user who holds the admin.repository-providers permission directly', function () {
    $user = permissionUser(['admin.repository-providers']);

    $this->actingAs($user);

    expect(RepositoryProviderResource::canViewAny())->toBeTrue()
        ->and(RepositoryProviderResource::canCreate())->toBeTrue();
});

it('denies RepositoryProvider access to a user without admin.repository-providers', function () {
    $user = permissionUser();

    $this->actingAs($user);

    expect(RepositoryProviderResource::canViewAny())->toBeFalse()
        ->and(RepositoryProviderResource::canCreate())->toBeFalse();
});

it('grants RepositoryProvider access through Plan and Admin roles via seeder', function () {
    $plan = permissionUser(roles: ['Plan']);
    $admin = permissionUser(roles: ['Admin']);

    $this->actingAs($plan);
    expect(RepositoryProviderResource::canViewAny())->toBeTrue();

    $this->actingAs($admin);
    expect(RepositoryProviderResource::canViewAny())->toBeTrue();
});

it('denies RepositoryProvider access to Reader and Triage roles', function () {
    $reader = permissionUser(roles: ['Reader']);
    $triage = permissionUser(roles: ['Triage']);

    $this->actingAs($reader);
    expect(RepositoryProviderResource::canViewAny())->toBeFalse();

    $this->actingAs($triage);
    expect(RepositoryProviderResource::canViewAny())->toBeFalse();
});

// ---------------------------------------------------------------------------
// CuratedLinksRelationManager — context.curate
// ---------------------------------------------------------------------------

it('hides curated link mutation controls from a user without context.curate', function () {
    $reader = permissionUser(roles: ['Reader']);
    $event = SecurityEvent::factory()->create();

    Livewire::actingAs($reader)
        ->test(CuratedLinksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => ViewSecurityEvent::class,
        ])
        ->call('loadTable')
        ->assertDontSee('Add curated link');
});

it('shows curated link mutation controls to a user with context.curate directly', function () {
    $user = permissionUser(['alerts.view', 'context.curate']);
    $event = SecurityEvent::factory()->create();

    Livewire::actingAs($user)
        ->test(CuratedLinksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => ViewSecurityEvent::class,
        ])
        ->call('loadTable')
        ->assertSee('Add curated link');
});

it('shows curated link mutation controls to Plan and Admin roles via seeder', function () {
    $plan = permissionUser(roles: ['Plan']);
    $event = SecurityEvent::factory()->create();

    Livewire::actingAs($plan)
        ->test(CuratedLinksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => ViewSecurityEvent::class,
        ])
        ->call('loadTable')
        ->assertSee('Add curated link');
});

// ---------------------------------------------------------------------------
// Verify permissions exist in the seeder output
// ---------------------------------------------------------------------------

it('seeder creates the expected permissions', function () {
    expect(Permission::findByName('admin.repository-providers', 'web'))->not->toBeNull()
        ->and(Permission::findByName('context.curate', 'web'))->not->toBeNull();
});

it('Plan role receives expected permissions through cumulative seeder model', function () {
    $plan = permissionUser(roles: ['Plan']);

    expect($plan->can('admin.repository-providers'))->toBeTrue()
        ->and($plan->can('context.curate'))->toBeTrue();
});

it('Admin role inherits expected permissions through cumulative seeder model', function () {
    $admin = permissionUser(roles: ['Admin']);

    expect($admin->can('admin.repository-providers'))->toBeTrue()
        ->and($admin->can('context.curate'))->toBeTrue();
});

it('Reader role does not have elevated permissions', function () {
    $reader = permissionUser(roles: ['Reader']);

    expect($reader->can('admin.repository-providers'))->toBeFalse()
        ->and($reader->can('context.curate'))->toBeFalse();
});
