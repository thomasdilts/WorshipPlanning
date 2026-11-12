<?php

namespace App\Services;

use App\Enums\AssignmentResponseMethod;
use App\Enums\AssignmentResponseStatus;
use App\Enums\AssignmentState;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\User;
use App\Notifications\AssignmentResponseReceivedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Messaging\Services\DeclineMessages;
use Spatie\Permission\PermissionRegistrar;

/**
 * Records volunteer (or team-leader / verbal) responses against the current assignee.
 *
 * Decline never clears the assignment — the position stays filled until a coordinator reassigns,
 * while the derived state becomes Declined so staffing views reopen the need.
 */
class AssignmentResponder
{
    public function __construct(
        protected AssignmentStateResolver $states,
    ) {}

    public function confirm(EventTask $task, User $actor, ?string $note = null): EventTaskResponse
    {
        return $this->respond(
            $task,
            $actor,
            AssignmentResponseStatus::Confirmed,
            AssignmentResponseMethod::Replied,
            $note,
            asVerbal: false,
        );
    }

    public function decline(EventTask $task, User $actor, ?string $note = null): EventTaskResponse
    {
        return $this->respond(
            $task,
            $actor,
            AssignmentResponseStatus::Declined,
            AssignmentResponseMethod::Replied,
            $note,
            asVerbal: false,
        );
    }

    /**
     * Coordinator records that the person/team accepted verbally.
     */
    public function confirmVerbally(EventTask $task, User $recorder, ?string $note = null): EventTaskResponse
    {
        return $this->respond(
            $task,
            $recorder,
            AssignmentResponseStatus::Confirmed,
            AssignmentResponseMethod::Verbal,
            $note,
            asVerbal: true,
        );
    }

    public function canSelfRespond(EventTask $task, User $actor): bool
    {
        if ((int) $task->event?->church_id !== (int) $actor->church_id) {
            return false;
        }

        if (filled($task->assigned_user_id) && (int) $task->assigned_user_id === (int) $actor->id) {
            return true;
        }

        if (filled($task->assigned_team_id)) {
            $task->loadMissing('assignedTeam');

            return $task->assignedTeam?->isLeader($actor) ?? false;
        }

        return false;
    }

    public function canRecordVerbal(EventTask $task, User $actor): bool
    {
        $task->loadMissing('event');

        if ((int) $task->event?->church_id !== (int) $actor->church_id) {
            return false;
        }

        if (! filled($task->assigned_user_id) && ! filled($task->assigned_team_id)) {
            return false;
        }

        return $actor->can('update', $task->event);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    protected function respond(
        EventTask $task,
        User $actor,
        AssignmentResponseStatus $status,
        AssignmentResponseMethod $method,
        ?string $note,
        bool $asVerbal,
    ): EventTaskResponse {
        $task->loadMissing(['event', 'assignedTeam', 'responses']);

        if ($task->event === null) {
            throw ValidationException::withMessages([
                'task' => __('This assignment is no longer available.'),
            ]);
        }

        if ($asVerbal) {
            if (! $this->canRecordVerbal($task, $actor)) {
                throw new AuthorizationException;
            }
        } elseif (! $this->canSelfRespond($task, $actor)) {
            throw new AuthorizationException;
        }

        if (! filled($task->assigned_user_id) && ! filled($task->assigned_team_id)) {
            throw ValidationException::withMessages([
                'task' => __('Nothing is assigned to respond for.'),
            ]);
        }

        $current = $this->states->for($task);

        if ($current === AssignmentState::Cancelled) {
            throw ValidationException::withMessages([
                'task' => __('This invitation was cancelled.'),
            ]);
        }

        $response = DB::transaction(function () use ($task, $actor, $status, $method, $note, $asVerbal): EventTaskResponse {
            $fresh = EventTask::query()->lockForUpdate()->findOrFail($task->id);

            if (
                (int) $fresh->assigned_user_id !== (int) $task->assigned_user_id
                || (int) $fresh->assigned_team_id !== (int) $task->assigned_team_id
            ) {
                throw ValidationException::withMessages([
                    'task' => __('This assignment changed. Refresh and try again.'),
                ]);
            }

            $fresh->loadMissing(['event', 'responses']);

            $existing = $this->latestMatchingResponse($fresh, $status, $method);

            if ($existing !== null) {
                return $existing;
            }

            $response = EventTaskResponse::query()->create([
                'church_id' => (int) $fresh->event->church_id,
                'event_task_id' => $fresh->id,
                'assignee_user_id' => $fresh->assigned_user_id,
                'assignee_team_id' => $fresh->assigned_team_id,
                'responder_user_id' => $asVerbal ? null : $actor->id,
                'recorded_by_user_id' => $asVerbal ? $actor->id : null,
                'status' => $status,
                'method' => $method,
                'occurred_at' => now(),
                'note' => filled($note) ? trim($note) : null,
            ]);

            if (! $asVerbal) {
                $this->notifyCoordinators($fresh, $actor, $status);
            }

            return $response;
        });

        // After the commit, so a slow or failing delivery never holds the lock or undoes the decline.
        if ($response->wasRecentlyCreated && $status === AssignmentResponseStatus::Declined) {
            app(DeclineMessages::class)->send($task->fresh(), $actor);
        }

        return $response;
    }

    /**
     * Same status + method already recorded for the current assignee → treat as a retry.
     */
    protected function latestMatchingResponse(
        EventTask $task,
        AssignmentResponseStatus $status,
        AssignmentResponseMethod $method,
    ): ?EventTaskResponse {
        $isPerson = filled($task->assigned_user_id);

        $latest = $task->responses
            ->filter(function (EventTaskResponse $response) use ($task, $isPerson): bool {
                if (! $response->status->changesResponse()) {
                    return false;
                }

                return $isPerson
                    ? (int) $response->assignee_user_id === (int) $task->assigned_user_id
                    : (int) $response->assignee_team_id === (int) $task->assigned_team_id;
            })
            ->last();

        if ($latest === null) {
            return null;
        }

        if ($latest->status !== $status || $latest->method !== $method) {
            return null;
        }

        return $latest;
    }

    protected function notifyCoordinators(EventTask $task, User $responder, AssignmentResponseStatus $status): void
    {
        $task->loadMissing('event');
        $event = $task->event;

        if ($event === null) {
            return;
        }

        $state = match ($status) {
            AssignmentResponseStatus::Confirmed => AssignmentState::Confirmed,
            AssignmentResponseStatus::Declined => AssignmentState::Declined,
            default => $this->states->for($task->fresh(['responses'])),
        };

        app(PermissionRegistrar::class)->setPermissionsTeamId($event->church_id);

        $recipients = User::query()
            ->where('church_id', $event->church_id)
            ->whereKeyNot($responder->id)
            ->get()
            ->filter(fn (User $user): bool => $user->can('update', $event))
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send(
            $recipients,
            new AssignmentResponseReceivedNotification($task, $responder, $state),
        );
    }
}
