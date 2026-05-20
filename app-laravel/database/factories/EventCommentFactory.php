<?php

namespace Database\Factories;

use App\Models\EventComment;
use App\Models\SecurityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventComment>
 */
class EventCommentFactory extends Factory
{
    protected $model = EventComment::class;

    public function definition(): array
    {
        return [
            'event_id' => SecurityEvent::factory(),
            'body' => $this->faker->paragraph(),
            'author_user_id' => null,
            'upstream_comment_id' => null,
            'created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
        ];
    }

    public function upstream(): static
    {
        return $this->state([
            'author_user_id' => null,
            'upstream_comment_id' => (string) $this->faker->numberBetween(1000, 99999),
        ]);
    }
}
