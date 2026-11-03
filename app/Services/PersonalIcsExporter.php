<?php

namespace App\Services;

use App\Enums\AssignmentState;
use App\Models\EventTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One-shot personal calendar download for My schedule (not a subscription).
 */
class PersonalIcsExporter
{
    public function __construct(
        protected AssignmentRequiredWindow $windows,
        protected AssignmentStateResolver $states,
    ) {}

    public function download(User $user): StreamedResponse
    {
        $body = $this->build($user);
        $filename = 'my-schedule-'.now()->format('Y-m-d').'.ics';

        return response()->streamDownload(function () use ($body): void {
            echo $body;
        }, $filename, [
            'Content-Type' => 'text/calendar; charset=utf-8',
        ]);
    }

    public function build(User $user): string
    {
        $tasks = $this->upcomingTasks($user);
        $states = $this->states->forMany($tasks);
        $product = 'WorshipPlanning';
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.$product.'//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape(__('My schedule')),
        ];

        foreach ($tasks as $task) {
            $state = $states[$task->id] ?? AssignmentState::Draft;

            if (in_array($state, [AssignmentState::Declined, AssignmentState::Cancelled], true)) {
                continue;
            }

            $window = $this->windows->forTask($task, $task->event);

            if ($window === null) {
                continue;
            }

            $uid = 'event-task-'.$task->id.'@worshipplanner';
            $summary = $task->displayName();
            $eventName = $task->event?->name;

            if (filled($eventName)) {
                $summary .= ' — '.$eventName;
            }

            $description = collect([
                $state->label(),
                $eventName,
                $user->church?->name,
            ])->filter()->implode("\n");

            $status = in_array($state, [AssignmentState::Confirmed, AssignmentState::ConfirmedVerbally], true) ? 'CONFIRMED' : 'TENTATIVE';

            $lines = [
                ...$lines,
                'BEGIN:VEVENT',
                'UID:'.$uid,
                'DTSTAMP:'.$this->utcStamp(now()),
                'DTSTART:'.$this->utcStamp($window['from']),
                'DTEND:'.$this->utcStamp($window['to']),
                'SUMMARY:'.$this->escape($summary),
                'DESCRIPTION:'.$this->escape($description),
                'STATUS:'.$status,
                'END:VEVENT',
            ];
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * @return Collection<int, EventTask>
     */
    protected function upcomingTasks(User $user): Collection
    {
        $ledTeamIds = $user->ledTeams()->pluck('teams.id');

        return EventTask::query()
            ->with(['event.church', 'template', 'assignedTeam', 'responses'])
            ->where(function (Builder $query) use ($user, $ledTeamIds): void {
                $query->where('assigned_user_id', $user->id);

                if ($ledTeamIds->isNotEmpty()) {
                    $query->orWhereIn('assigned_team_id', $ledTeamIds);
                }
            })
            ->whereHas('event', function (Builder $query) use ($user): void {
                $query->where('church_id', $user->church_id)
                    ->where('starts_at', '>=', now()->startOfDay());
            })
            ->get()
            ->sortBy(fn (EventTask $task): string => $task->event?->starts_at?->toDateTimeString() ?? '')
            ->values();
    }

    protected function utcStamp(Carbon $time): string
    {
        return $time->clone()->utc()->format('Ymd\THis\Z');
    }

    protected function escape(string $value): string
    {
        $value = str_replace(["\r\n", "\n", "\r"], '\\n', $value);

        return addcslashes($value, '\,;');
    }
}
