<?php

use App\Models\RepositoryProvider;
use App\Models\SoftwareSystem;
use App\SourceCode\RepositoryMappingService;

it('persists a Bitbucket Cloud repository url when creating a mapping', function () {
    $provider = RepositoryProvider::factory()->bitbucket()->create();
    $system = SoftwareSystem::factory()->create();

    $mapping = app(RepositoryMappingService::class)->create($system, null, [
        'repository_provider_id' => $provider->id,
        'repository_name' => 'platform-api',
        'default_branch' => 'main',
    ]);

    expect($mapping->repository_url)->toBe('https://bitbucket.org/appsec-scout/platform-api');
});
