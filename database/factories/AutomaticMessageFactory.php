<?php

namespace Database\Factories;

use App\Enums\MessageTemplatePurpose;
use App\Models\AutomaticMessage;
use App\Models\Church;
use App\Models\MessageTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomaticMessage>
 */
class AutomaticMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'church_id' => Church::factory(),
            'name' => fake()->words(3, true),
            'message_template_id' => fn (array $attributes) => MessageTemplate::query()->create([
                'church_id' => $attributes['church_id'],
                'name' => 'Reminder',
                'purpose' => MessageTemplatePurpose::Service,
                'subject' => 'Reminder: {{event_name}}',
                'body' => '<p>See you soon.</p>',
                'is_active' => true,
            ])->id,
            'hours_before' => 72,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
