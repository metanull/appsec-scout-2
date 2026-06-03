<?php

use App\Filament\Resources\RepositoryProviderResource;
use App\Filament\Resources\Shared\RelationManagers\RepositoryMappingsRelationManager;
use App\Filament\Resources\SoftwareSystemResource\Pages\ViewSoftwareSystem;
use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\SourceCode\RepositoryMappingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('lets readers view repository mappings without mutation controls', function () {
    $user = enrolledMappingUser();
    $user->syncRoles(['Reader']);

    $system = SoftwareSystem::factory()->create();

    Livewire::actingAs($user)
        ->test(RepositoryMappingsRelationManager::class, [
            'ownerRecord' => $system,
            'pageClass' => ViewSoftwareSystem::class,
        ])
        ->call('loadTable')
        ->assertSee('Repository mappings')
        ->assertDontSee('Add mapping');

    expect(RepositoryProviderResource::canViewAny())->toBeFalse();
});

it('lets plan users create azure and github mappings through the relation manager', function () {
    $user = enrolledMappingUser();
    $user->syncRoles(['Plan']);

    Livewire::actingAs($user);

    expect(RepositoryProviderResource::canViewAny())->toBeTrue();

    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    $azureProvider = RepositoryProvider::factory()->azureRepos()->create([
        'name' => 'Azure Repos',
        'base_url' => 'https://dev.azure.com/acme',
    ]);
    $githubProvider = RepositoryProvider::factory()->github()->create([
        'name' => 'GitHub',
        'base_url' => 'https://github.com/acme',
    ]);

    $service = app(RepositoryMappingService::class);

    $service->create($system, $user, [
        'repository_provider_id' => $azureProvider->id,
        'repository_name' => 'payments-api',
        'default_branch' => 'main',
        'path_prefix' => 'services/payments',
    ]);

    $service->create($container, $user, [
        'repository_provider_id' => $githubProvider->id,
        'repository_name' => 'payments-container',
        'default_branch' => 'develop',
        'path_prefix' => 'containers/payments',
    ]);

    expect(RepositoryMapping::query()->where('owner_type', SoftwareSystem::class)->where('owner_id', $system->id)->count())->toBe(1)
        ->and(RepositoryMapping::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->count())->toBe(1);
});

it('rejects duplicate and unsafe repository mappings', function () {
    $user = enrolledMappingUser();
    $user->syncRoles(['Plan']);

    $system = SoftwareSystem::factory()->create();
    $provider = RepositoryProvider::factory()->azureRepos()->create([
        'name' => 'Azure Repos',
        'base_url' => 'https://dev.azure.com/acme',
    ]);

    $service = app(RepositoryMappingService::class);

    $service->create($system, $user, [
        'repository_provider_id' => $provider->id,
        'repository_name' => 'payments-api',
        'default_branch' => 'main',
        'path_prefix' => 'services/payments',
    ]);

    expect(fn () => $service->create($system, $user, [
        'repository_provider_id' => $provider->id,
        'repository_name' => 'payments-api',
        'default_branch' => 'main',
        'path_prefix' => 'services/payments',
    ]))->toThrow(ValidationException::class);

    expect(fn () => RepositoryProvider::factory()->azureRepos()->create([
        'name' => 'Unsafe Azure Repos',
        'base_url' => 'javascript:alert(1)',
    ]))->toThrow(ValidationException::class);
});

function enrolledMappingUser(): User
{
    return User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
}
