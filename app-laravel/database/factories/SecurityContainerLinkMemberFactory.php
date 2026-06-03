<?php

namespace Database\Factories;

use App\Models\SecurityContainer;
use App\Models\SecurityContainerLink;
use App\Models\SecurityContainerLinkMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityContainerLinkMember>
 */
class SecurityContainerLinkMemberFactory extends Factory
{
    protected $model = SecurityContainerLinkMember::class;

    public function definition(): array
    {
        return [
            'link_id' => SecurityContainerLink::factory(),
            'security_container_id' => SecurityContainer::factory(),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
