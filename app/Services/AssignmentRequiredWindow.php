<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Absolute time windows a person/team must be free for a position.
 *
 * Timed tasks use the chained program timeline from the event start.
 * Global / untimed tasks fall back to the full event window.
 *
 * @phpstan-type Window array{from: Carbon, to: Carbon, task: EventTask}
 */
class AssignmentRequiredWindow
{
    public function __construct(
        protected EventTaskTimeline $timeline,
    ) {}

    /**
     * @return array{from: Carbon, to: Carbon}|null
     */
    public function forTask(EventTask $task, ?Event $event = null): ?array
    {
        $event ??= $task->event;

        if ($event === null || $event->starts_at === null) {
            return null;
        }

        if ($task->isGlobal() || blank($task->duration)) {
            return [
                'from' => $event->starts_at->copy(),
                'to' => $event->endsAt(),
            ];
        }

        foreach ($this->timedWindows($event) as $window) {
            if ((int) $window['task']->id === (int) $task->id) {
                return [
                    'from' => $window['from'],
                    'to' => $window['to'],
                ];
            }
        }

        return [
            'from' => $event->starts_at->copy(),
            'to' => $event->endsAt(),
        ];
    }

    /**
     * @return Collection<int, Window>
     */
    public function timedWindows(Event $event): Collection
    {
        return $this->timeline->windows($event);
    }

    /**
     * Same person scheduled on overlapping required windows in one event.
     * Multiple roles are allowed; this only surfaces an accurate warning.
     *
     * @return list<array{user_id: int, tasks: list<string>, from: Carbon, to: Carbon}>
     */
    public function overlappingPersonCommitments(Event $event): array
    {
        $byUser = [];

        foreach ($this->timedWindows($event) as $window) {
            $userId = $window['task']->assigned_user_id;

            if (! filled($userId)) {
                continue;
            }

            $byUser[(int) $userId][] = $window;
        }

        $overlaps = [];

        foreach ($byUser as $userId => $windows) {
            if (count($windows) < 2) {
                continue;
            }

            usort($windows, fn (array $a, array $b): int => $a['from'] <=> $b['from']);

            for ($i = 0; $i < count($windows); $i++) {
                for ($j = $i + 1; $j < count($windows); $j++) {
                    if ($windows[$j]['from']->gte($windows[$i]['to'])) {
                        break;
                    }

                    $overlapEnd = $windows[$i]['to']->lt($windows[$j]['to'])
                        ? $windows[$i]['to']->copy()
                        : $windows[$j]['to']->copy();

                    $overlaps[] = [
                        'user_id' => $userId,
                        'tasks' => [
                            $windows[$i]['task']->displayName(),
                            $windows[$j]['task']->displayName(),
                        ],
                        'from' => $windows[$j]['from']->copy(),
                        'to' => $overlapEnd,
                    ];
                }
            }
        }

        return $overlaps;
    }
}
