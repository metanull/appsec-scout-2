<?php

namespace Database\Factories;

use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityContainer>
 */
class SecurityContainerFactory extends Factory
{
    protected $model = SecurityContainer::class;

    public function definition(): array
    {
        return [
            'software_system_id' => SoftwareSystem::factory(),
            'source_container_id' => $this->faker->uuid(),
            'name' => $this->faker->words(2, true),
            'kind' => $this->faker->optional()->randomElement(['repository', 'scan', 'pipeline']),
            'url' => $this->faker->optional()->url(),
            'metadata' => null,
            'first_seen_at' => $this->faker->dateTimeBetween('-1 year', '-6 months'),
            'last_seen_at' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'synced_at' => now(),
        ];
    }

    public function forSystem(SoftwareSystem $system): static
    {
        return $this->state(['software_system_id' => $system->id]);
    }

    /** A container its source reports under $source, manually re-linked by an operator to $linkedTo. */
    public function relinked(SoftwareSystem $source, SoftwareSystem $linkedTo): static
    {
        return $this->state([
            'source_software_system_id' => $source->id,
            'software_system_id' => $linkedTo->id,
            'system_overridden_at' => now(),
        ]);
    }
}
