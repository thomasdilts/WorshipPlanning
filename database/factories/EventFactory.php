<?php

namespace Database\Factories;

use App\Models\Church;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $starts = fake()->dateTimeBetween('+1 day', '+30 days');

        return [
            'church_id' => Church::factory(),
            'name' => fake()->randomElement(['Sunday Service', 'Evening Worship', 'Prayer Meeting']).' '.fake()->date('M j'),
            'description' => fake()->optional()->sentence(),
            'starts_at' => $starts,
        ];
    }
}
