<?php

namespace App\Services;

use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentState;
use App\Models\AutomaticMessage;
use App\Models\Event;
use App\Models\EventTask;
use App\Support\AvailableLocales;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;
use Modules\Messaging\Services\EventMessageService;
use Modules\Messaging\Support\MessagingAvailability;
use Throwable;

/**
 * Sends each active automatic message once per service, when the service starts within its "hours before".
 *
 * Runs from the scheduler every hour. A service is only messaged when its send moment (start minus hours
 * before) is after the automatic message was created, so a new rule never messages services that were
 * already inside its window. People who declined or were cancelled are left out.
 */
class AutomaticMessageDispatcher
{
    use Localizable;

    private const SKIPPED_STATES = [AssignmentState::Declined, AssignmentState::Cancelled];

    public function __construct(
        protected EventMessageService $messages,
        protected AssignmentStateResolver $states,
        protected MessagingAvailability $availability,
        protected CurrentChurch $currentChurch,
    ) {}

    /**
     * @return int The number of services a message was sent for.
     */
    public function sendDue(): int
    {
        $sentFor = 0;

        AutomaticMessage::query()
            ->withoutGlobalScope('church')
            ->where('is_active', true)
            ->whereNotNull('message_template_id')
            ->with(['church', 'template', 'createdBy.language'])
            ->orderBy('id')
            ->each(function (AutomaticMessage $automaticMessage) use (&$sentFor): void {
                $sentFor += $this->sendFor($automaticMessage);
            });

        return $sentFor;
    }

    protected function sendFor(AutomaticMessage $automaticMessage): int
    {
        $church = $automaticMessage->church;

        if ($church === null || ! $automaticMessage->template?->is_active || ! $this->availability->anyEnabled($church)) {
            return 0;
        }

        $previousChurch = $this->currentChurch->get();
        $this->currentChurch->set($church);

        try {
            $sentFor = 0;

            foreach ($this->dueEvents($automaticMessage) as $event) {
                $sentFor += $this->sendForEvent($automaticMessage, $event) ? 1 : 0;
            }

            return $sentFor;
        } finally {
            $this->currentChurch->set($previousChurch);
        }
    }

    /**
     * @return Collection<int, Event>
     */
    protected function dueEvents(AutomaticMessage $automaticMessage): Collection
    {
        $hours = $automaticMessage->hours_before;

        return Event::query()
            ->where('events.church_id', $automaticMessage->church_id)
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours($hours))
            ->where('starts_at', '>=', $automaticMessage->created_at->copy()->addHours($hours))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('automatic_message_deliveries')
                ->whereColumn('automatic_message_deliveries.event_id', 'events.id')
                ->where('automatic_message_deliveries.automatic_message_id', $automaticMessage->id))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Records the delivery first, so a failure or an overlapping run can never send the same message twice.
     */
    protected function sendForEvent(AutomaticMessage $automaticMessage, Event $event): bool
    {
        $taskIds = $this->recipientTaskIds($event);

        if ($taskIds === []) {
            return false;
        }

        $recorded = DB::table('automatic_message_deliveries')->insertOrIgnore([
            'automatic_message_id' => $automaticMessage->id,
            'event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($recorded === 0) {
            return false;
        }

        $template = $automaticMessage->template;

        try {
            $sent = $this->withLocale($this->locale($automaticMessage), fn () => $this->messages->create(
                event: $event,
                author: $automaticMessage->createdBy,
                body: $template->body,
                subject: $template->subject,
                templateId: $template->id,
                sendNow: true,
                textBody: $template->text_body,
                recipientTaskIds: $taskIds,
                customMessage: $automaticMessage->custom_message,
            ));
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        DB::table('automatic_message_deliveries')
            ->where(['automatic_message_id' => $automaticMessage->id, 'event_id' => $event->id])
            ->update(['message_count' => $sent->count(), 'updated_at' => now()]);

        return true;
    }

    /**
     * Every task with a person or a team, except those that were declined or cancelled.
     *
     * @return list<int>
     */
    protected function recipientTaskIds(Event $event): array
    {
        $tasks = $event->tasks()
            ->where(fn ($query) => $query->whereNotNull('assigned_user_id')->orWhereNotNull('assigned_team_id'))
            ->with('responses')
            ->get();
        $states = $this->states->forMany($tasks);

        return $tasks
            ->reject(fn (EventTask $task): bool => in_array($states[$task->id] ?? null, self::SKIPPED_STATES, true))
            ->map(fn (EventTask $task): int => (int) $task->id)
            ->values()
            ->all();
    }

    /**
     * Placeholders such as dates use the language of the admin who set the message up.
     */
    protected function locale(AutomaticMessage $automaticMessage): string
    {
        $iso = strtolower((string) $automaticMessage->createdBy?->language?->iso);

        return in_array($iso, AvailableLocales::codes(), true) ? $iso : (string) config('app.locale', 'en');
    }
}
