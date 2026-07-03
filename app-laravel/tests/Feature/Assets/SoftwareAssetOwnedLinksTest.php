<?php

use App\CuratedLinks\CuratedLinkService;
use App\Models\CuratedLink;
use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;
use App\Models\SoftwareAsset;
use App\Models\User;
use App\SourceCode\RepositoryMappingService;

it('accepts a software asset as a curated link owner', function () {
    $user = User::factory()->create();
    $asset = SoftwareAsset::factory()->create();

    $link = app(CuratedLinkService::class)->create($asset, $user, [
        'label' => 'Architecture doc',
        'kind' => 'other',
        'url' => 'https://docs.example.com/architecture',
    ]);

    expect(CuratedLink::query()->where('owner_type', SoftwareAsset::class)->where('owner_id', $asset->id)->count())->toBe(1)
        ->and($link->owner)->toBeInstanceOf(SoftwareAsset::class);
});

it('accepts a software asset as a repository mapping owner', function () {
    $user = User::factory()->create();
    $asset = SoftwareAsset::factory()->create();
    $provider = RepositoryProvider::factory()->azureRepos()->create([
        'name' => 'Azure Repos',
        'base_url' => 'https://dev.azure.com/acme',
    ]);

    $mapping = app(RepositoryMappingService::class)->create($asset, $user, [
        'repository_provider_id' => $provider->id,
        'repository_name' => 'payments-api',
        'default_branch' => 'main',
    ]);

    expect(RepositoryMapping::query()->where('owner_type', SoftwareAsset::class)->where('owner_id', $asset->id)->count())->toBe(1)
        ->and($mapping->owner)->toBeInstanceOf(SoftwareAsset::class);
});
