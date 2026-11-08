<?php

namespace App\Services;

use App\Enums\AssignmentState;
use App\Models\Event;
use App\Models\EventTask;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

class EventStaffingSummary
{
    public function __construct(
        protected AssignmentStateResolver $states,
        protected EventTaskTimeline $timeline,
    ) {}

    /**
     * @return array{
     *     total: int,
     *     vacant: int,
     *     awaiting: int,
     *     declined: int,
     *     confirmed: int,
     *     conflict: int,
     *     ready: bool,
     *     calculated_ends_at: ?Carbon
     * }
     */
    public function for(Event $event): array
    {
        $event->loadMissing(['tasks.template', 'tasks.assignedUser', 'tasks.assignedTeam', 'tasks.responses']);

        $staffingTasks = $event->tasks->filter(function (EventTask $task): bool {
            return (bool) ($task->template?->requiresPersonOrTeam() ?? false);
        })->values();

        $states = $this->states->forMany(new EloquentCollection($staffingTasks->all()));

        $vacant = 0;
        $awaiting = 0;
        $declined = 0;
        $confirmed = 0;

        foreach ($staffingTasks as $task) {
            $state = $states[$task->id] ?? AssignmentState::Vacant;

            match ($state) {
                AssignmentState::Vacant => $vacant++,
                AssignmentState::Invited, AssignmentState::Informed => $awaiting++,
                AssignmentState::Declined, AssignmentState::Cancelled => $declined++,
                AssignmentState::Confirmed, AssignmentState::ConfirmedVerbally, AssignmentState::AssumedTeam => $confirmed++,
                default => null,
            };

            if ($state === AssignmentState::Draft) {
                $awaiting++;
            }
        }

        return [
            'total' => $staffingTasks->count(),
            'vacant' => $vacant,
            'awaiting' => $awaiting,
            'declined' => $declined,
            'confirmed' => $confirmed,
            'conflict' => 0,
            'ready' => $vacant === 0 && $declined === 0 && $awaiting === 0,
            'calculated_ends_at' => $this->calculatedEndsAt($event),
        ];
    }

    /**
     * When the program ends: the end of the last task after the start, or null without any.
     */
    public function calculatedEndsAt(Event $event): ?Carbon
    {
        $last = $this->timeline->windows($event)->last(fn (array $window): bool => ! $window['task']->before_start);

        return $last['to'] ?? null;
    }
}
