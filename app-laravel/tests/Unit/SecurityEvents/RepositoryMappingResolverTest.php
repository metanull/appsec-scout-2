<?php

use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\SecurityEvents\RepositoryMappingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prefers container mappings over system mappings', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();
    $systemProvider = RepositoryProvider::factory()->github()->create([
        'base_url' => 'https://github.com/acme/system',
    ]);
    $containerProvider = RepositoryProvider::factory()->github()->create([
        'base_url' => 'https://github.com/acme/container',
    ]);

    $systemMapping = RepositoryMapping::factory()
        ->forSystem($system)
        ->withProvider($systemProvider)
        ->create([
            'repository_name' => 'system-repo',
            'path_prefix' => 'src',
        ]);

    $containerMapping = RepositoryMapping::factory()
        ->forContainer($container)
        ->withProvider($containerProvider)
        ->create([
            'repository_name' => 'container-repo',
            'path_prefix' => 'src',
        ]);

    $event = SecurityEvent::factory()->forContainer($container)->create();

    $resolved = app(RepositoryMappingResolver::class)->resolve($event);

    expect($resolved?->is($containerMapping))->toBeTrue()
        ->and($resolved?->is($systemMapping))->toBeFalse();
});

it('falls back to the system mapping when no container mapping exists', function () {
    $system = SoftwareSystem::factory()->create();
    $provider = RepositoryProvider::factory()->github()->create([
        'base_url' => 'https://github.com/acme/system',
    ]);

    $systemMapping = RepositoryMapping::factory()
        ->forSystem($system)
        ->withProvider($provider)
        ->create([
            'repository_name' => 'system-repo',
            'path_prefix' => 'src',
        ]);

    $event = SecurityEvent::factory()->forSystem($system)->create();

    $resolved = app(RepositoryMappingResolver::class)->resolve($event);

    expect($resolved?->is($systemMapping))->toBeTrue();
});

it('returns null when no mapping exists', function () {
    $event = SecurityEvent::factory()->create();

    expect(app(RepositoryMappingResolver::class)->resolve($event))->toBeNull();
});
