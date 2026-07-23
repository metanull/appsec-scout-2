<?php

use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\Sources\AzDo\AzDoNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function nativeAzdoContainer(): SecurityContainer
{
    $system = SoftwareSystem::factory()->create(['source_id' => AzDoNormalizer::SOURCE_ID]);

    return SecurityContainer::factory()->forSystem($system)->create([
        'kind' => 'repository',
        'url' => 'https://dev.azure.com/org/Proj/_git/repo',
        'metadata' => [
            'source' => ['provider' => 'azure-repos'],
            'code' => ['default_branch' => 'main'],
        ],
    ]);
}

function makeMapping(SecurityContainer $container, ?User $author): RepositoryMapping
{
    $provider = RepositoryProvider::factory()->azureRepos()->create();

    return $container->repositoryMappings()->create([
        'repository_provider_id' => $provider->id,
        'repository_name' => $container->name,
        'default_branch' => 'main',
        'created_by_user_id' => $author?->id,
        'metadata' => null,
    ]);
}

it('prunes only machine-generated mappings on containers that have their own identity', function () {
    // 1) redundant machine mapping on a native-identity AzDO container → pruned
    $redundant = makeMapping(nativeAzdoContainer(), null);

    // 2) operator-authored mapping on a native-identity container → kept
    $operatorAuthored = makeMapping(nativeAzdoContainer(), User::factory()->create());

    // 3) machine mapping on an AzDO container WITHOUT native identity → kept
    $noIdentitySystem = SoftwareSystem::factory()->create(['source_id' => AzDoNormalizer::SOURCE_ID]);
    $noIdentityContainer = SecurityContainer::factory()->forSystem($noIdentitySystem)->create(['url' => null, 'metadata' => null]);
    $keptNoIdentity = makeMapping($noIdentityContainer, null);

    // 4) machine mapping on a non-AzDO (app-based) container → kept
    $asocSystem = SoftwareSystem::factory()->create(['source_id' => 'asoc']);
    $asocContainer = SecurityContainer::factory()->forSystem($asocSystem)->create(['url' => null, 'metadata' => null]);
    $keptAsoc = makeMapping($asocContainer, null);

    $migration = require database_path('migrations/2026_07_16_000200_prune_redundant_azdo_repository_mappings.php');
    $migration->up();

    expect(RepositoryMapping::query()->whereKey($redundant->id)->exists())->toBeFalse()
        ->and(RepositoryMapping::query()->whereKey($operatorAuthored->id)->exists())->toBeTrue()
        ->and(RepositoryMapping::query()->whereKey($keptNoIdentity->id)->exists())->toBeTrue()
        ->and(RepositoryMapping::query()->whereKey($keptAsoc->id)->exists())->toBeTrue();
});
