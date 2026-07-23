<?php

use App\Models\Enums\RepositoryProviderType;
use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\SourceCode\RepositoryCodeIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function azdoContainer(array $overrides = []): SecurityContainer
{
    $system = SoftwareSystem::factory()->create();

    return SecurityContainer::factory()->forSystem($system)->create(array_merge([
        'url' => 'https://dev.azure.com/EESC-CoR/PW-API/_git/consultation-api',
        'metadata' => [
            'source' => ['provider' => 'azure-repos'],
            'code' => ['default_branch' => 'main'],
        ],
    ], $overrides));
}

it('derives an identity from the container’s own stored facts when no mapping exists', function () {
    $container = azdoContainer();

    $identity = app(RepositoryCodeIdentityResolver::class)->resolve($container, $container->softwareSystem);

    expect($identity)->not->toBeNull()
        ->and($identity?->providerType)->toBe(RepositoryProviderType::AzureRepos)
        ->and($identity?->repositoryBrowseUrl)->toBe('https://dev.azure.com/EESC-CoR/PW-API/_git/consultation-api')
        ->and($identity?->defaultBranch)->toBe('main')
        ->and($identity?->pathPrefix)->toBeNull();
});

it('prefers an operator RepositoryMapping override over the container’s own identity', function () {
    $container = azdoContainer();
    $provider = RepositoryProvider::factory()->github()->create(['base_url' => 'https://github.com/acme']);

    RepositoryMapping::factory()
        ->forContainer($container)
        ->withProvider($provider)
        ->create(['repository_name' => 'mirror', 'default_branch' => 'trunk']);

    $container->load('repositoryMappings.repositoryProvider');

    $identity = app(RepositoryCodeIdentityResolver::class)->resolve($container, $container->softwareSystem);

    expect($identity?->providerType)->toBe(RepositoryProviderType::GitHub)
        ->and($identity?->repositoryBrowseUrl)->toBe('https://github.com/acme/mirror')
        ->and($identity?->defaultBranch)->toBe('trunk');
});

it('returns null when the container has no provider fact to build a link from', function () {
    $container = azdoContainer(['metadata' => ['code' => ['default_branch' => 'main']]]);

    $identity = app(RepositoryCodeIdentityResolver::class)->resolve($container, $container->softwareSystem);

    expect($identity)->toBeNull();
});

it('returns null when there is neither a container nor a mapping', function () {
    $identity = app(RepositoryCodeIdentityResolver::class)->resolve(null, null);

    expect($identity)->toBeNull();
});
