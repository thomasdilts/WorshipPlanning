<?php

namespace Modules\Messaging\Services;

use App\Enums\AssignmentResponseStatus;
use App\Enums\AssignmentState;
use App\Models\Event;
use App\Models\EventMessage;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\User;
use App\Services\AssignmentResponseLink;
use App\Services\AssignmentStateResolver;
use App\Services\EventPlanStateResolver;
use App\Services\InvitationDeliveryPreview;
use App\Support\HtmlMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Messaging\Data\OutboundMessageData;
use Modules\Messaging\Data\SendResult;
use Modules\Messaging\Support\MessagePlaceholders;
use Modules\Teams\Services\TeamMessageService;

class EventMessageService
{
    public function __construct(
        protected MessageSender $sender,
        protected TeamMessageService $teams,
        protected InvitationDeliveryPreview $preview,
        protected AssignmentStateResolver $states,
        protected AssignmentResponseLink $responseLinks,
        protected EventPlanStateResolver $planStates,
        protected MessagePlaceholders $placeholders,
    ) {}

    /**
     * Creates one message per person and task, so each row tracks exactly one answer.
     *
     * @param  list<int>  $recipientUserIds  People: one message for each task they have on the event.
     * @param  list<int>  $recipientTeamIds  Teams: one message per team task, to the team's leader(s).
     * @param  list<int>  $recipientTaskIds  Tasks: a message to the task's person, or its team's leader(s).
     * @return Collection<int, EventMessage>
     */
    public function create(
        Event $event,
        ?User $author,
        ?string $body,
        ?string $subject,
        array $recipientUserIds = [],
        ?int $templateId = null,
        bool $sendNow = false,
        array $recipientTeamIds = [],
        ?string $textBody = null,
        array $recipientTaskIds = [],
        ?string $customMessage = null,
    ): Collection {
        $pairs = $this->preview->recipientPairs($event, $recipientTaskIds, $recipientUserIds, $recipientTeamIds);

        if ($pairs === []) {
            throw new \InvalidArgumentException('Select at least one assignee or team on this event.');
        }

        if (blank(HtmlMessage::toPlainText($body)) && blank($textBody)) {
            throw new \InvalidArgumentException('Write an HTML or a text message.');
        }

        $messages = DB::transaction(fn (): Collection => collect($pairs)->map(function (array $pair) use ($event, $author, $body, $textBody, $subject, $templateId, $customMessage): EventMessage {
            $message = EventMessage::query()->create([
                'church_id' => $event->church_id,
                'event_id' => $event->id,
                'event_task_id' => $pair['task']?->id,
                'created_by_user_id' => $author?->id,
                'message_template_id' => $templateId,
                'subject' => $subject,
                'body' => $body,
                'text_body' => filled($textBody) ? $textBody : null,
                'custom_message' => filled($customMessage) ? trim($customMessage) : null,
                'status' => EventMessage::STATUS_DRAFT,
            ]);

            $message->recipients()->sync([$pair['user']->id => ['delivery_status' => 'pending']]);

            return $message;
        }));

        if ($sendNow) {
            $messages->each(fn (EventMessage $message) => $this->send($message));
        }

        return $messages->map(fn (EventMessage $message): EventMessage => $message->fresh(['recipients']));
    }

    public function send(EventMessage $message): EventMessage
    {
        $message->loadMissing(['recipients', 'createdBy', 'event.tasks.template', 'event.tasks.assignedTeam', 'event.tasks.responses']);

        if ($message->status === EventMessage::STATUS_SENT) {
            return $message;
        }

        $successes = 0;
        $failures = 0;

        foreach ($message->recipients as $recipient) {
            $prior = $recipient->pivot->delivery_status ?? 'pending';

            if ($prior === 'sent') {
                $successes++;

                continue;
            }

            $tasks = $this->tasksForMessage($message, $recipient);
            $errors = [];

            if ($tasks === null) {
                $message->recipients()->updateExistingPivot($recipient->id, [
                    'delivery_status' => 'failed',
                    'delivery_error' => __('The task is no longer assigned to this person.'),
                ]);
                $failures++;

                continue;
            }

            // One message per task, so every message carries the accept / decline links for exactly one task.
            foreach ($tasks->isEmpty() ? [null] : $tasks->all() as $task) {
                $result = $this->deliver($message, $recipient, $task);

                if (! $result->success) {
                    $errors[] = $result->error;

                    continue;
                }

                if ($task !== null) {
                    $this->markInvited($task, $recipient);
                }
            }

            $delivered = $errors === [];

            $message->recipients()->updateExistingPivot($recipient->id, [
                'delivery_status' => $delivered ? 'sent' : 'failed',
                'delivery_error' => $delivered ? null : implode("\n", array_filter($errors)),
            ]);

            $delivered ? $successes++ : $failures++;
        }

        $status = match (true) {
            $successes > 0 && $failures === 0 => EventMessage::STATUS_SENT,
            $successes > 0 && $failures > 0 => EventMessage::STATUS_PARTIAL,
            default => EventMessage::STATUS_FAILED,
        };

        $message->update([
            'status' => $status,
            'sent_at' => $message->sent_at ?? now(),
        ]);

        if ($successes > 0 && $message->event !== null) {
            $this->planStates->markShared($message->event);
        }

        return $message->fresh(['recipients']);
    }

    protected function deliver(EventMessage $message, User $recipient, ?EventTask $task): SendResult
    {
        return $this->sender->sendToUser($recipient, $this->compose($message, $recipient, $task));
    }

    /**
     * The message exactly as this recipient gets it for this task: placeholders filled in, links added.
     */
    public function compose(EventMessage $message, User $recipient, ?EventTask $task): OutboundMessageData
    {
        $values = $this->placeholders->valuesFor($recipient, $message->event, $task ? collect([$task]) : collect());
        $links = $this->responseLinks->placeholderValues($task, $recipient);
        $custom = TemplateMessages::customMessageValues($message->custom_message);
        $values[MessagePlaceholders::SENDER] = (string) $message->createdBy?->displayName();
        $textValues = [...$values, ...$custom['text'], ...$links['text']];
        $htmlLinks = [...$custom['html'], ...$links['html']];

        $html = filled(HtmlMessage::toPlainText($message->body))
            ? $this->placeholders->render((string) $message->body, $textValues, escapeHtml: true, htmlValues: $htmlLinks)
            : nl2br($this->placeholders->render(e((string) $message->text_body), $textValues, escapeHtml: true, htmlValues: $htmlLinks));
        $text = filled($message->text_body)
            ? $this->placeholders->render($message->text_body, $textValues, escapeHtml: false)
            : $this->htmlToText($this->placeholders->render((string) $message->body, $textValues, escapeHtml: false));

        $hasOwnLinks = MessagePlaceholders::usesResponseLinks($message->body)
            || MessagePlaceholders::usesResponseLinks($message->text_body);

        if ($task !== null && ! $hasOwnLinks) {
            $html .= $this->responseLinks->footerHtml($task, $recipient);
            $text .= $this->responseLinks->footerText($task, $recipient);
        }

        $subject = $message->subject ?: ($message->event?->name ?? 'Message');

        return new OutboundMessageData(
            subject: $this->placeholders->render($subject, $textValues, escapeHtml: false),
            body: $html,
            templateKey: $message->template?->key,
            meta: [
                'event_id' => $message->event_id,
                'event_message_id' => $message->id,
                'event_task_id' => $task?->id,
            ],
            textBody: $text,
            whatsappTemplate: $message->template?->whatsapp_template,
            placeholders: $textValues,
        );
    }

    /**
     * What each recipient receives, for previewing a message as it was (or will be) sent.
     *
     * @return list<array{user: User, task: ?EventTask, message: OutboundMessageData}>
     */
    public function previews(EventMessage $message): array
    {
        $message->loadMissing(['recipients', 'createdBy', 'event', 'template']);
        $previews = [];

        foreach ($message->recipients as $recipient) {
            foreach ($this->tasksForMessage($message, $recipient) ?? [null] as $task) {
                $previews[] = [
                    'user' => $recipient,
                    'task' => $task,
                    'message' => $this->compose($message, $recipient, $task),
                ];
            }
        }

        return $previews;
    }

    /**
     * Plain text from HTML that keeps link targets, so links still work in SMS / WhatsApp.
     */
    protected function htmlToText(string $html): string
    {
        $withUrls = (string) preg_replace_callback(
            '/<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
            function (array $match): string {
                $label = trim(strip_tags($match[3]));
                $url = html_entity_decode($match[2]);

                return $label === '' || $label === $url ? $url : $label.': '.$url;
            },
            $html,
        );

        return HtmlMessage::toPlainText($withUrls);
    }

    /**
     * Deliver the message again to every recipient, regardless of earlier outcomes.
     */
    public function resend(EventMessage $message): EventMessage
    {
        $message->recipients()->newPivotQuery()->update([
            'delivery_status' => 'pending',
            'delivery_error' => null,
        ]);

        $message->update([
            'status' => EventMessage::STATUS_DRAFT,
            'sent_at' => null,
        ]);

        return $this->send($message->fresh());
    }

    /**
     * The message's own task, or — for older messages without one — every task the recipient has.
     * Null when the message's task is no longer assigned to the recipient.
     *
     * @return Collection<int, EventTask>|null
     */
    protected function tasksForMessage(EventMessage $message, User $recipient): ?Collection
    {
        if ($message->event_task_id === null) {
            return $this->tasksForRecipient($message->event, $recipient);
        }

        $tasks = $this->tasksForRecipient($message->event, $recipient)
            ->filter(fn (EventTask $task): bool => (int) $task->id === (int) $message->event_task_id)
            ->values();

        return $tasks->isEmpty() ? null : $tasks;
    }

    /**
     * @return Collection<int, EventTask>
     */
    public function tasksForRecipient(?Event $event, User $user): Collection
    {
        return $event?->tasksFor($user) ?? collect();
    }

    protected function markInvited(EventTask $task, User $recipient): void
    {
        $state = $this->states->for($task);

        if (in_array($state, [
            AssignmentState::Confirmed,
            AssignmentState::ConfirmedVerbally,
            AssignmentState::Declined,
            AssignmentState::Cancelled,
            AssignmentState::Invited,
        ], true)) {
            return;
        }

        $isPerson = (int) $task->assigned_user_id === (int) $recipient->id;
        $isTeamLeader = filled($task->assigned_team_id)
            && ($task->assignedTeam?->isLeader($recipient) ?? false);

        if (! $isPerson && ! $isTeamLeader) {
            return;
        }

        $task->loadMissing('event');

        EventTaskResponse::query()->create([
            'church_id' => (int) $task->event->church_id,
            'event_task_id' => $task->id,
            'assignee_user_id' => $task->assigned_user_id,
            'assignee_team_id' => $task->assigned_team_id,
            'recorded_by_user_id' => null,
            'responder_user_id' => null,
            'status' => AssignmentResponseStatus::Invited,
            'method' => null,
            'requested_at' => now(),
            'occurred_at' => now(),
        ]);
    }
}
