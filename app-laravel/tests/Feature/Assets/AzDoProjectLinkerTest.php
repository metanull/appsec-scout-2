<?php

use App\Assets\AzDoProjectLinker;
use App\Credentials\Vault;
use App\Models\Enums\RepositoryProviderType;
use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Sources\AzDo\AzDoNormalizer;

beforeEach(function () {
    // Repo linking resolves the organization from the Source Control credential
    // (azdo-repos.*), not the alert-ingestion Source credential (azdo.*).
    app(Vault::class)->set('azdo-repos.organization', null, 'testorg');
});

it('creates and links a software asset for an unlinked azdo software system', function () {
    $system = SoftwareSystem::factory()->create([
        'source_id' => AzDoNormalizer::SOURCE_ID,
        'name' => 'TelCodes',
    ]);

    app(AzDoProjectLinker::class)->linkSystemToAsset($system);

    $system->refresh();

    expect($system->software_asset_id)->not()->toBeNull();

    $asset = SoftwareAsset::query()->findOrFail($system->software_asset_id);
    expect($asset->name)->toBe('TelCodes')
        ->and($asset->softwareSystems()->pluck('id')->all())->toBe([$system->id]);
});

it('does not create a second asset when the software system is already linked', function () {
    $asset = SoftwareAsset::factory()->create(['name' => 'Existing Asset']);
    $system = SoftwareSystem::factory()->create([
        'source_id' => AzDoNormalizer::SOURCE_ID,
        'software_asset_id' => $asset->id,
    ]);

    app(AzDoProjectLinker::class)->linkSystemToAsset($system);

    expect(SoftwareAsset::query()->count())->toBe(1)
        ->and($system->fresh()->software_asset_id)->toBe($asset->id);
});

it('does not create an asset for non-azdo software systems', function () {
    $system = SoftwareSystem::factory()->create(['source_id' => 'asoc']);

    app(AzDoProjectLinker::class)->linkSystemToAsset($system);

    expect($system->fresh()->software_asset_id)->toBeNull()
        ->and(SoftwareAsset::query()->count())->toBe(0);
});

it('creates a project-scoped repository mapping for an azdo repository container', function () {
    $system = SoftwareSystem::factory()->create([
        'source_id' => AzDoNormalizer::SOURCE_ID,
        'name' => 'TelCodes',
    ]);
    $container = SecurityContainer::factory()->forSystem($system)->create([
        'name' => 'tinc-front',
        'kind' => 'repository',
        'metadata' => ['code.default_branch' => 'develop'],
    ]);

    app(AzDoProjectLinker::class)->ensureRepositoryMapping($container);

    $mapping = RepositoryMapping::query()
        ->where('owner_type', SecurityContainer::class)
        ->where('owner_id', $container->id)
        ->first();

    expect($mapping)->not()->toBeNull()
        ->and($mapping?->repository_name)->toBe('tinc-front')
        ->and($mapping?->default_branch)->toBe('develop')
        ->and($mapping?->repository_url)->toBe('https://dev.azure.com/testorg/TelCodes/_git/tinc-front');

    $provider = RepositoryProvider::query()->findOrFail($mapping?->repository_provider_id);
    expect($provider->getRawOriginal('provider_type'))->toBe(RepositoryProviderType::AzureRepos->value)
        ->and($provider->base_url)->toBe('https://dev.azure.com/testorg/TelCodes');
});

it('does not backfill a repository mapping when only the Source credential organization is present', function () {
    // The alert-ingestion Source credential (azdo.*) is intentionally no longer
    // sufficient for repo linking: without the Source Control organization
    // (azdo-repos.*) no mapping is created.
    app(Vault::class)->set('azdo-repos.organization', null, '');
    app(Vault::class)->set('azdo.organization', null, 'testorg');

    $system = SoftwareSystem::factory()->create([
        'source_id' => AzDoNormalizer::SOURCE_ID,
        'name' => 'TelCodes',
    ]);
    $container = SecurityContainer::factory()->forSystem($system)->create([
        'name' => 'tinc-front',
        'kind' => 'repository',
    ]);

    app(AzDoProjectLinker::class)->ensureRepositoryMapping($container);

    expect(RepositoryMapping::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->count())->toBe(0);
});

it('does not backfill a repository mapping when the container already carries its own code identity', function () {
    $system = SoftwareSystem::factory()->create([
        'source_id' => AzDoNormalizer::SOURCE_ID,
        'name' => 'TelCodes',
    ]);
    $container = SecurityContainer::factory()->forSystem($system)->create([
        'name' => 'tinc-front',
        'kind' => 'repository',
        'url' => 'https://dev.azure.com/testorg/TelCodes/_git/tinc-front',
        'metadata' => [
            'source' => ['provider' => 'azure-repos'],
            'code' => ['default_branch' => 'develop'],
        ],
    ]);

    app(AzDoProjectLinker::class)->ensureRepositoryMapping($container);

    expect(RepositoryMapping::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->count())->toBe(0);
});

it('does not create a duplicate repository mapping on repeated calls', function () {
    $system = SoftwareSystem::factory()->create(['source_id' => AzDoNormalizer::SOURCE_ID, 'name' => 'TelCodes']);
    $container = SecurityContainer::factory()->forSystem($system)->create(['kind' => 'repository', 'name' => 'tinc-front']);

    $linker = app(AzDoProjectLinker::class);
    $linker->ensureRepositoryMapping($container);
    $linker->ensureRepositoryMapping($container);

    expect(RepositoryMapping::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->count())->toBe(1);
});

it('skips non-repository containers and non-azdo systems', function () {
    $azdoSystem = SoftwareSystem::factory()->create(['source_id' => AzDoNormalizer::SOURCE_ID]);
    $pipelineContainer = SecurityContainer::factory()->forSystem($azdoSystem)->create(['kind' => 'pipeline']);

    $otherSystem = SoftwareSystem::factory()->create(['source_id' => 'asoc']);
    $otherContainer = SecurityContainer::factory()->forSystem($otherSystem)->create(['kind' => 'repository']);

    $linker = app(AzDoProjectLinker::class);
    $linker->ensureRepositoryMapping($pipelineContainer);
    $linker->ensureRepositoryMapping($otherContainer);

    expect(RepositoryMapping::query()->count())->toBe(0);
});
