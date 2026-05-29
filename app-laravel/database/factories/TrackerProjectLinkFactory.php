<?php

namespace Database\Factories;

use App\Models\SoftwareSystem;
use App\Models\TrackerProjectLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrackerProjectLink>
 */
class TrackerProjectLinkFactory extends Factory
{
    protected $model = TrackerProjectLink::class;

    public function definition(): array
    {
        return [
            'owner_type' => SoftwareSystem::class,
            'owner_id' => SoftwareSystem::factory(),
            'tracker_id' => 'jira',
            'project_key' => strtoupper($this->faker->lexify('???')),
            'project_name' => $this->faker->words(3, true),
            'is_default' => false,
            'created_by_user_id' => null,
            'metadata' => null,
        ];
    }

    public function forSystem(SoftwareSystem $system): static
    {
        return $this->state(['owner_type' => SoftwareSystem::class, 'owner_id' => $system->id]);
    }

    public function withCreator(User $user): static
    {
        return $this->state(['created_by_user_id' => $user->id]);
    }
}
