<?php

namespace Database\Factories;

use App\Models\Enums\RepositoryProviderType;
use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepositoryMapping>
 */
class RepositoryMappingFactory extends Factory
{
    protected $model = RepositoryMapping::class;

    public function definition(): array
    {
        $repositoryName = $this->faker->slug(2);

        return [
            'owner_type' => SoftwareSystem::class,
            'owner_id' => SoftwareSystem::factory(),
            'repository_provider_id' => RepositoryProvider::factory()->azureRepos(),
            'repository_name' => $repositoryName,
            'repository_url' => $this->repositoryUrl(RepositoryProviderType::AzureRepos->value, 'https://dev.azure.com/appsec-scout', $repositoryName),
            'default_branch' => 'main',
            'path_prefix' => null,
            'created_by_user_id' => null,
            'metadata' => null,
        ];
    }

    public function forSystem(SoftwareSystem $system): static
    {
        return $this->state([
            'owner_type' => SoftwareSystem::class,
            'owner_id' => $system->id,
        ]);
    }

    public function forContainer(SecurityContainer $container): static
    {
        return $this->state([
            'owner_type' => SecurityContainer::class,
            'owner_id' => $container->id,
        ]);
    }

    public function azureRepos(): static
    {
        return $this->state(function (array $attributes): array {
            $repositoryName = $attributes['repository_name'] ?? $this->faker->slug(2);

            return [
                'repository_provider_id' => RepositoryProvider::factory()->azureRepos(),
                'repository_url' => $this->repositoryUrl(RepositoryProviderType::AzureRepos->value, 'https://dev.azure.com/appsec-scout', $repositoryName),
            ];
        });
    }

    public function github(): static
    {
        return $this->state(function (array $attributes): array {
            $repositoryName = $attributes['repository_name'] ?? $this->faker->slug(2);

            return [
                'repository_provider_id' => RepositoryProvider::factory()->github(),
                'repository_url' => $this->repositoryUrl(RepositoryProviderType::GitHub->value, 'https://github.com/appsec-scout', $repositoryName),
            ];
        });
    }

    public function withProvider(RepositoryProvider $provider): static
    {
        return $this->state(function (array $attributes) use ($provider): array {
            $repositoryName = $attributes['repository_name'] ?? $this->faker->slug(2);
            $providerType = (string) $provider->getRawOriginal('provider_type');

            return [
                'repository_provider_id' => $provider->id,
                'repository_url' => $this->repositoryUrl($providerType, $provider->base_url, $repositoryName),
            ];
        });
    }

    public function withCreator(User $user): static
    {
        return $this->state(['created_by_user_id' => $user->id]);
    }

    private function repositoryUrl(string $providerType, string $baseUrl, string $repositoryName): string
    {
        $normalizedBaseUrl = rtrim($baseUrl, '/');

        return $providerType === RepositoryProviderType::AzureRepos->value
            ? $normalizedBaseUrl . '/_git/' . $repositoryName
            : $normalizedBaseUrl . '/' . $repositoryName;
    }
}
