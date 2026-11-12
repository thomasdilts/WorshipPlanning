<?php

namespace Database\Factories;

use App\Enums\FieldUsage;
use App\Models\Church;
use App\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskTemplate>
 */
class TaskTemplateFactory extends Factory
{
    protected $model = TaskTemplate::class;

    public function definition(): array
    {
        return [
            'church_id' => Church::factory(),
            'name' => fake()->randomElement([
                'Welcome',
                'Opening song',
                'Prayer',
                'Sermon',
                'Offering',
                'Closing song',
            ]),
            'description' => fake()->optional()->sentence(),
            'default_order' => fake()->numberBetween(0, 20),
            'default_duration' => fake()->randomElement([5, 10, 15, 20, 30]),
            'use_globally' => false,
            'default_global_order' => 0,
            'person_usage' => FieldUsage::Optional,
            'team_usage' => FieldUsage::NotUsed,
            'description_usage' => FieldUsage::Optional,
            'special_needs_usage' => FieldUsage::Optional,
            'song_usage' => FieldUsage::NotUsed,
            'allow_manual_song_entry' => false,
            'picture_usage' => FieldUsage::NotUsed,
            'file_usage' => FieldUsage::NotUsed,
            'bible_usage' => FieldUsage::NotUsed,
            'is_active' => true,
            'show_in_spreadsheet' => false,
            'receives_decline_messages' => false,
        ];
    }
}
