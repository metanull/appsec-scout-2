<?php

use App\Filament\Resources\RepositoryProviderResource;
use App\Filament\Resources\RepositoryProviderResource\Pages\ListRepositoryProviders;
use App\Models\RepositoryProvider;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('makes a repository provider row link to the view page, not the edit page', function () {
    $provider = RepositoryProvider::factory()->azureRepos()->create([
        'name' => 'Azure Repos',
        'base_url' => 'https://dev.azure.com/acme',
    ]);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);

    $recordUrl = Livewire::actingAs($user)
        ->test(ListRepositoryProviders::class)
        ->instance()
        ->getTable()
        ->getRecordUrl($provider);

    expect($recordUrl)->toBe(RepositoryProviderResource::getUrl('view', ['record' => $provider]));
});

it('keeps the edit and delete row actions working after grouping them', function () {
    $provider = RepositoryProvider::factory()->azureRepos()->create([
        'name' => 'Azure Repos',
        'base_url' => 'https://dev.azure.com/acme',
    ]);
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);

    Livewire::actingAs($user)
        ->test(ListRepositoryProviders::class)
        ->assertTableActionVisible('edit', $provider)
        ->assertTableActionVisible('delete', $provider);
});

it('lets an authorized user view a repository provider', function () {
    $provider = RepositoryProvider::factory()->azureRepos()->create([
        'name' => 'Azure Repos',
        'base_url' => 'https://dev.azure.com/acme',
    ]);
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $this->actingAs($user)
        ->get(RepositoryProviderResource::getUrl('view', ['record' => $provider]))
        ->assertOk()
        ->assertSee('Azure Repos');
});

it('denies view access to a user without the admin.repository-providers permission', function () {
    $provider = RepositoryProvider::factory()->azureRepos()->create([
        'name' => 'Azure Repos',
        'base_url' => 'https://dev.azure.com/acme',
    ]);
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    $this->actingAs($user)
        ->get(RepositoryProviderResource::getUrl('view', ['record' => $provider]))
        ->assertForbidden();
});
