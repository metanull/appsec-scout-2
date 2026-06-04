<?php

namespace Database\Factories;

use App\Models\Enums\RepositoryProviderType;
use App\Models\RepositoryProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepositoryProvider>
 */
class RepositoryProviderFactory extends Factory
{
    protected $model = RepositoryProvider::class;

    public function definition(): array
    {
        return [
            'provider_type' => RepositoryProviderType::AzureRepos,
            'name' => $this->faker->company() . ' Azure Repos',
            'base_url' => 'https://dev.azure.com/appsec-scout',
            'metadata' => null,
        ];
    }

    public function azureRepos(): static
    {
        return $this->state([
            'provider_type' => RepositoryProviderType::AzureRepos,
            'name' => $this->faker->company() . ' Azure Repos',
            'base_url' => 'https://dev.azure.com/appsec-scout',
        ]);
    }

    public function github(): static
    {
        return $this->state([
            'provider_type' => RepositoryProviderType::GitHub,
            'name' => $this->faker->company() . ' GitHub',
            'base_url' => 'https://github.com/appsec-scout',
        ]);
    }
}
