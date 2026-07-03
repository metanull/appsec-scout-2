<?php

namespace Database\Factories;

use App\Models\SoftwareAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SoftwareAsset>
 */
class SoftwareAssetFactory extends Factory
{
    protected $model = SoftwareAsset::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'metadata' => null,
        ];
    }
}
