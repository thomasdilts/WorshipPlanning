<?php

namespace App\Services;

use App\Models\EventTask;
use App\Models\Team;
use App\Models\User;
use App\Support\DateTimeFormats;
use App\Support\UserAvatar;
use Illuminate\Support\Carbon;

class TeamWorkspaceSummary
{
    /**
     * @return array{
     *     member_count: int,
     *     leader_count: int,
     *     has_leader: bool,
     *     leaders: list<array{id: int, name: string, avatar: string}>,
     *     next_commitments: list<array{task_id: int, role: string, event: string, when: string, state: string}>,
     *     upcoming_count: int,
     *     file_count: int,
     *     unavailability_count: int
     * }
     */
    public function for(Team $team): array
    {
        $team->loadMissing(['members', 'type', 'sharedFiles']);

        $leaders = $team->members
            ->filter(fn (User $user): bool => (bool) $user->pivot->is_leader)
            ->values();

        $from = now()->startOfDay();

        $upcoming = EventTask::query()
            ->where('assigned_team_id', $team->id)
            ->whereHas('event', fn ($q) => $q->where('starts_at', '>=', $from))
            ->with(['event', 'template', 'responses'])
            ->get()
            ->sortBy(fn (EventTask $task): string => $task->event?->starts_at?->toDateTimeString() ?? '')
            ->values();

        $states = app(AssignmentStateResolver::class)->forMany($upcoming);

        $next = $upcoming->take(5)->map(function (EventTask $task) use ($states): array {
            $when = $task->event?->starts_at instanceof Carbon
                ? $task->event->starts_at->timezone(config('app.timezone'))->translatedFormat(DateTimeFormats::dateWithWeekdayTime())
                : '—';

            $state = $states[$task->id] ?? null;

            return [
                'task_id' => (int) $task->id,
                'role' => $task->displayName(),
                'event' => $task->event?->name ?? __('Event'),
                'when' => $when,
                'state' => $state?->label() ?? '—',
            ];
        })->all();

        $unavailabilityCount = $team->unavailabilities()
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->count();

        return [
            'member_count' => $team->members->count(),
            'leader_count' => $leaders->count(),
            'has_leader' => $leaders->isNotEmpty(),
            'leaders' => $leaders->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => $user->display_name ?: $user->name,
                'avatar' => UserAvatar::html($user),
            ])->all(),
            'next_commitments' => $next,
            'upcoming_count' => $upcoming->count(),
            'file_count' => $team->sharedFiles->count(),
            'unavailability_count' => $unavailabilityCount,
        ];
    }
}
