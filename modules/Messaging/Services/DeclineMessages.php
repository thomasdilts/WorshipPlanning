<?php

namespace Modules\Messaging\Services;

use App\Enums\AssignmentState;
use App\Filament\Resources\Events\EventResource;
use App\Models\EventTask;
use App\Models\Language;
use App\Models\User;
use App\Services\AssignmentStateResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Localizable;
use Modules\Messaging\Support\MessagePlaceholders;
use Modules\Messaging\Support\MessagingAvailability;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Tells the person who assigned a task that it was declined, using the message template chosen in the
 * church settings. When nobody else assigned it, the people on the service's "receive all decline
 * messages" tasks are told instead.
 */
class DeclineMessages
{
    use Localizable;

    private const SKIPPED_STATES = [AssignmentState::Declined, AssignmentState::Cancelled];

    public function __construct(
        protected MessageSender $sender,
        protected TemplateMessages $templates,
        protected MessagePlaceholders $placeholders,
        protected AssignmentStateResolver $states,
        protected MessagingAvailability $availability,
    ) {}

    /**
     * A failed delivery is reported but never undoes the decline.
     *
     * @return int The number of people the message was sent to.
     */
    public function send(EventTask $declinedTask, User $decliner): int
    {
        $declinedTask->loadMissing(['event.church.declineMessageTemplate', 'assignedTeam', 'assignedBy']);
        $event = $declinedTask->event;
        $church = $event?->church;
        $template = $church?->declineMessageTemplate;

        if ($template === null || ! $template->is_active || ! $this->availability->anyEnabled($church)) {
            return 0;
        }

        $sent = 0;

        foreach ($this->recipients($declinedTask, $decliner) as $recipient) {
            try {
                $locale = Language::query()->find($recipient->language_id)?->iso ?? app()->getLocale();

                $message = $this->withLocale($locale, fn () => $this->templates->compose(
                    $template,
                    $recipient,
                    meta: [
                        'kind' => 'decline_notice',
                        'event_id' => $event->id,
                        'event_task_id' => $declinedTask->id,
                    ],
                    fallbackSubject: $event->name,
                    values: [
                        ...$this->placeholders->valuesFor($recipient, $event, collect([$declinedTask])),
                        MessagePlaceholders::DECLINED_BY => $decliner->displayName(),
                        MessagePlaceholders::TASK_URL => $this->taskUrl($declinedTask, $recipient),
                        ...(filled($declinedTask->assignedTeam?->name)
                            ? [MessagePlaceholders::TEAM => (string) $declinedTask->assignedTeam->name]
                            : []),
                    ],
                ));

                $sent += $this->sender->sendToUser($recipient, $message)->success ? 1 : 0;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $sent;
    }

    /**
     * The service page with the declined task opened: its edit form for people who can edit the
     * service, else the read-only view. Timed tasks are on the Order tab, whole-service ones on People.
     */
    public function taskUrl(EventTask $task, User $viewer): string
    {
        $event = $task->event;
        app(PermissionRegistrar::class)->setPermissionsTeamId($event->church_id);

        return EventResource::getUrl($viewer->can('update', $event) ? 'edit' : 'view', [
            'record' => $event,
            'relation' => $task->isGlobal() ? '1' : '0',
            'task' => $task->id,
        ], panel: 'admin');
    }

    /**
     * The person who assigned the declined task. The people on the service's decline-message tasks
     * instead when the assigner is unknown, left the church, or is the one (or on the team) who declined.
     *
     * @return Collection<int, User>
     */
    protected function recipients(EventTask $declinedTask, User $decliner): Collection
    {
        $assigner = $this->assignerToTell($declinedTask, $decliner);

        return $assigner !== null
            ? collect([$assigner])
            : $this->declineMessageTaskPeople($declinedTask, $decliner);
    }

    protected function assignerToTell(EventTask $declinedTask, User $decliner): ?User
    {
        $assigner = $declinedTask->assignedBy;

        if ($assigner === null || $assigner->id === $decliner->id || $assigner->church_id !== $declinedTask->event->church_id) {
            return null;
        }

        $isOnDeclinedTeam = $declinedTask->assignedTeam?->members()->whereKey($assigner->id)->exists() ?? false;

        return $isOnDeclinedTeam ? null : $assigner;
    }

    /**
     * The people on the service's decline-message tasks, except the one who declined and anyone who
     * declined or was cancelled on that task themself.
     *
     * @return Collection<int, User>
     */
    protected function declineMessageTaskPeople(EventTask $declinedTask, User $decliner): Collection
    {
        $tasks = EventTask::query()
            ->where('event_id', $declinedTask->event_id)
            ->whereNotNull('assigned_user_id')
            ->where('assigned_user_id', '!=', $decliner->id)
            ->whereHas('template', fn ($query) => $query->withoutGlobalScope('church')->where('receives_decline_messages', true))
            ->with(['assignedUser.church', 'responses'])
            ->get();
        $states = $this->states->forMany($tasks);

        return $tasks
            ->reject(fn (EventTask $task): bool => in_array($states[$task->id] ?? null, self::SKIPPED_STATES, true))
            ->map(fn (EventTask $task): ?User => $task->assignedUser)
            ->filter()
            ->unique('id')
            ->values();
    }
}
