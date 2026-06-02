<?php

namespace Tests\Unit;

use App\Models\Enums\RepositoryProviderType;
use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ValueError;

class RepositoryMappingModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_applies_and_rolls_back_repository_provider_migrations_cleanly(): void
    {
        Artisan::call('migrate:fresh');

        $this->assertTrue(Schema::hasTable('repository_providers'));
        $this->assertTrue(Schema::hasTable('repository_mappings'));
    }

    public function test_casts_repository_provider_types_and_rejects_unsupported_values(): void
    {
        $provider = RepositoryProvider::factory()->github()->create();

        $this->assertSame(RepositoryProviderType::GitHub, $provider->provider_type);
        $this->assertSame('github', $provider->provider_type->value);
        $this->assertSame(['azure-repos', 'github'], RepositoryProviderType::values());

        $this->expectException(ValueError::class);

        RepositoryProvider::query()->create([
            'provider_type' => 'gitlab',
            'name' => 'Unsupported provider',
            'base_url' => 'https://gitlab.example.com/group',
            'metadata' => null,
        ]);
    }

    public function test_links_repository_mappings_to_systems_containers_providers_and_creators(): void
    {
        $system = SoftwareSystem::factory()->create();
        $container = SecurityContainer::factory()->forSystem($system)->create();
        $creator = User::factory()->create();
        $provider = RepositoryProvider::factory()->azureRepos()->create();

        $systemMapping = RepositoryMapping::factory()
            ->forSystem($system)
            ->withProvider($provider)
            ->withCreator($creator)
            ->create([
                'repository_name' => 'payments-api',
                'default_branch' => 'main',
                'path_prefix' => 'services/payments',
            ]);

        $containerMapping = RepositoryMapping::factory()
            ->forContainer($container)
            ->github()
            ->create([
                'repository_name' => 'payments-api-container',
                'default_branch' => 'develop',
                'path_prefix' => 'containers/payments',
            ]);

        $this->assertCount(1, $system->repositoryMappings);
        $this->assertCount(1, $container->repositoryMappings);
        $this->assertInstanceOf(SoftwareSystem::class, $systemMapping->owner);
        $systemRepositoryProvider = $systemMapping->repositoryProvider;
        $systemCreatedBy = $systemMapping->createdBy;
        $this->assertInstanceOf(RepositoryProvider::class, $systemRepositoryProvider);
        $this->assertInstanceOf(User::class, $systemCreatedBy);
        $this->assertSame(RepositoryProviderType::AzureRepos, $systemRepositoryProvider->provider_type);
        $this->assertInstanceOf(SecurityContainer::class, $containerMapping->owner);
        $containerRepositoryProvider = $containerMapping->repositoryProvider;
        $this->assertInstanceOf(RepositoryProvider::class, $containerRepositoryProvider);
        $this->assertSame(RepositoryProviderType::GitHub, $containerRepositoryProvider->provider_type);
        $this->assertSame('develop', $containerMapping->default_branch);
        $this->assertSame('containers/payments', $containerMapping->path_prefix);
    }
}
