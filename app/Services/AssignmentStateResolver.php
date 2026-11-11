<?php

namespace App\Services;

use App\Enums\AssignmentResponseStatus;
use App\Enums\AssignmentState;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Derives the effective state of a position from the task's current assignee and the response history.
 *
 * History written for a previous assignee never carries over to the current one.
 */
class AssignmentStateResolver
{
    public function for(EventTask $task): AssignmentState
    {
        $task->loadMissing('responses');

        return $this->resolve($task);
    }

    /**
     * @param  Collection<int, EventTask>  $tasks
     * @return array<int, AssignmentState>
     */
    public function forMany(Collection $tasks): array
    {
        if ($tasks instanceof EloquentCollection) {
            $tasks->loadMissing('responses');
        }

        return $tasks->mapWithKeys(fn (EventTask $task): array => [$task->id => $this->resolve($task)])->all();
    }

    private function resolve(EventTask $task): AssignmentState
    {
        $isPerson = filled($task->assigned_user_id);
        $isTeam = ! $isPerson && filled($task->assigned_team_id);

        if (! $isPerson && ! $isTeam) {
            return $task->hasOnlyTypedNames() ? AssignmentState::Named : AssignmentState::Vacant;
        }

        if ($task->isAcceptedVerbally()) {
            return AssignmentState::ConfirmedVerbally;
        }

        $history = $task->responses->filter(
            fn (EventTaskResponse $response): bool => $isPerson
                ? (int) $response->assignee_user_id === (int) $task->assigned_user_id
                : (int) $response->assignee_team_id === (int) $task->assigned_team_id
        );

        $latest = $history->last(fn (EventTaskResponse $response): bool => $response->status->changesResponse());

        if ($latest !== null) {
            return match ($latest->status) {
                AssignmentResponseStatus::Invited,
                AssignmentResponseStatus::ReconfirmRequested => AssignmentState::Invited,
                AssignmentResponseStatus::Confirmed => AssignmentState::Confirmed,
                AssignmentResponseStatus::Declined => AssignmentState::Declined,
                AssignmentResponseStatus::Cancelled => AssignmentState::Cancelled,
                default => AssignmentState::Unknown,
            };
        }

        if ($isTeam) {
            return AssignmentState::AssumedTeam;
        }

        return $history->isNotEmpty() ? AssignmentState::Informed : AssignmentState::Draft;
    }
}
