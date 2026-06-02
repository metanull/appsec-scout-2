<?php

namespace Database\Factories;

use App\Models\CuratedLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuratedLink>
 */
class CuratedLinkFactory extends Factory
{
    protected $model = CuratedLink::class;

    public function definition(): array
    {
        return [
            'owner_type' => null,
            'owner_id' => null,
            'label' => $this->faker->sentence(3),
            'url' => $this->faker->url(),
            'kind' => $this->faker->randomElement(CuratedLink::ALLOWED_KINDS),
            'created_by_user_id' => null,
        ];
    }
}
