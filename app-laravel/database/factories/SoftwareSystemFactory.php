<?php

namespace Database\Factories;

use App\Models\SoftwareSystem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SoftwareSystem>
 */
class SoftwareSystemFactory extends Factory
{
    protected $model = SoftwareSystem::class;

    public function definition(): array
    {
        return [
            'source_id' => $this->faker->randomElement(['azdo', 'asoc', 'detectify']),
            'source_system_id' => $this->faker->uuid(),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'url' => $this->faker->optional()->url(),
            'metadata' => null,
            'first_seen_at' => $this->faker->dateTimeBetween('-1 year', '-6 months'),
            'last_seen_at' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'synced_at' => now(),
        ];
    }

    public function azdo(): static
    {
        return $this->state([
            'source_id' => 'azdo',
            'source_system_id' => 'project-' . $this->faker->uuid(),
            'url' => 'https://dev.azure.com/testorg/_apis/projects/' . $this->faker->uuid(),
        ]);
    }
}
