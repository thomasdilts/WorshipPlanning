<?php

namespace Database\Factories;

use App\Models\Church;
use App\Models\EventMessage;
use App\Models\OutboundMessage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A system message by default; use teamMessage() or eventMessage() for messages people wrote.
 *
 * @extends Factory<OutboundMessage>
 */
class OutboundMessageFactory extends Factory
{
    protected $model = OutboundMessage::class;

    public function definition(): array
    {
        return [
            'church_id' => Church::factory(),
            'user_id' => User::factory(),
            'channel' => 'email',
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'status' => 'sent',
            'meta' => [],
        ];
    }

    public function teamMessage(Team $team, User $from): static
    {
        return $this->state(fn (): array => [
            'church_id' => $team->church_id,
            'meta' => ['team_id' => $team->id, 'from_user_id' => $from->id],
        ]);
    }

    public function eventMessage(EventMessage $message): static
    {
        return $this->state(fn (): array => [
            'church_id' => $message->church_id,
            'event_id' => $message->event_id,
            'event_message_id' => $message->id,
            'meta' => ['event_id' => $message->event_id, 'event_message_id' => $message->id],
        ]);
    }
}
