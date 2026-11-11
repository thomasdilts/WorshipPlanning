<?php

namespace Modules\Unavailability\Services;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\Team;
use App\Models\Unavailability;
use App\Models\User;
use App\Services\AssignmentRequiredWindow;
use App\Support\DateTimeFormats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class UnavailabilityConflictService
{
    public function __construct(
        protected ChurchModuleManager $modules,
        protected AssignmentRequiredWindow $requiredWindows,
    ) {}

    public function enabledFor(?Church $church): bool
    {
        if ($church === null) {
            return false;
        }

        return $this->modules->isEnabled($church, 'unavailability');
    }

    public function userIsUnavailable(
        User $user,
        Carbon|string $from,
        Carbon|string $to,
        ?Church $church = null,
    ): bool {
        $church ??= $user->church;

        if (! $this->enabledFor($church)) {
            return false;
        }

        return Unavailability::query()
            ->personal()
            ->where('user_id', $user->id)
            ->overlapping($from, $to)
            ->exists();
    }

    public function teamIsUnavailable(
        Team $team,
        Carbon|string $from,
        Carbon|string $to,
        ?Church $church = null,
    ): bool {
        $church ??= $team->church;

        if (! $this->enabledFor($church)) {
            return false;
        }

        return Unavailability::query()
            ->forTeams()
            ->where('team_id', $team->id)
            ->overlapping($from, $to)
            ->exists();
    }

    /**
     * @return Collection<int, Unavailability>
     */
    public function forUser(
        User $user,
        Carbon|string $from,
        Carbon|string $to,
    ): Collection {
        return Unavailability::query()
            ->personal()
            ->where('user_id', $user->id)
            ->overlapping($from, $to)
            ->orderBy('starts_on')
            ->get();
    }

    /**
     * @return Collection<int, Unavailability>
     */
    public function forTeam(
        Team $team,
        Carbon|string $from,
        Carbon|string $to,
    ): Collection {
        return Unavailability::query()
            ->forTeams()
            ->where('team_id', $team->id)
            ->overlapping($from, $to)
            ->orderBy('starts_on')
            ->get();
    }

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    public function windowForTask(EventTask $task, Event $event): array
    {
        return $this->requiredWindows->forTask($task, $event) ?? [
            'from' => $event->starts_at->copy(),
            'to' => ($event->ends_at ?? $event->starts_at)->copy(),
        ];
    }

    /**
     * Tasks whose assignee is unavailable during that task's required window
     * (not the entire event span when the task is timed).
     *
     * @return Collection<int, array{task: EventTask, subject: string, ranges: Collection<int, Unavailability>}>
     */
    public function conflictsForEvent(Event $event): Collection
    {
        if (! $this->enabledFor($event->church)) {
            return collect();
        }

        $event->loadMissing(['tasks.assignedUser', 'tasks.assignedTeam', 'tasks.template', 'church']);

        $conflicts = collect();

        foreach ($event->tasks as $task) {
            $window = $this->windowForTask($task, $event);
            $user = $task->assignedUser;

            if ($user !== null) {
                $ranges = $this->forUser($user, $window['from'], $window['to']);

                if ($ranges->isNotEmpty()) {
                    $conflicts->push([
                        'task' => $task,
                        'subject' => $user->display_name ?: $user->name,
                        'ranges' => $ranges,
                    ]);
                }
            }

            $team = $task->assignedTeam;

            if ($team !== null) {
                $ranges = $this->forTeam($team, $window['from'], $window['to']);

                if ($ranges->isNotEmpty()) {
                    $conflicts->push([
                        'task' => $task,
                        'subject' => $team->name,
                        'ranges' => $ranges,
                    ]);
                }
            }
        }

        return $conflicts;
    }

    /**
     * @return list<string>
     */
    public function warningMessagesForEvent(Event $event): array
    {
        $messages = [];

        foreach ($this->conflictsForEvent($event) as $conflict) {
            /** @var EventTask $task */
            $task = $conflict['task'];
            /** @var string $subject */
            $subject = $conflict['subject'];
            /** @var Collection<int, Unavailability> $ranges */
            $ranges = $conflict['ranges'];

            $rangeLabel = $ranges->map(fn (Unavailability $u) => $u->label())->implode('; ');

            $messages[] = __(':subject is unavailable (:ranges) — assigned to “:task”.', [
                'subject' => $subject,
                'ranges' => $rangeLabel,
                'task' => $task->displayName(),
            ]);
        }

        foreach ($this->requiredWindows->overlappingPersonCommitments($event) as $overlap) {
            $user = User::query()->find($overlap['user_id']);
            $name = $user?->display_name ?: ($user?->name ?? __('Someone'));

            $messages[] = __(':subject is assigned to overlapping roles “:a” and “:b” (:from – :to).', [
                'subject' => $name,
                'a' => $overlap['tasks'][0],
                'b' => $overlap['tasks'][1],
                'from' => DateTimeFormats::formatTime($overlap['from'], $event->church),
                'to' => DateTimeFormats::formatTime($overlap['to'], $event->church),
            ]);
        }

        return $messages;
    }
}
