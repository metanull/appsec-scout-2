<?php

namespace Database\Factories;

use App\Models\SoftwareSystemLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SoftwareSystemLink>
 */
class SoftwareSystemLinkFactory extends Factory
{
    protected $model = SoftwareSystemLink::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
        ];
    }
}
