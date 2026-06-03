<?php

namespace Database\Factories;

use App\Models\SecurityContainerLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityContainerLink>
 */
class SecurityContainerLinkFactory extends Factory
{
    protected $model = SecurityContainerLink::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
        ];
    }
}
