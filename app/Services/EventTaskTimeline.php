<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type Window array{from: Carbon, to: Carbon, task: EventTask}
 */
class EventTaskTimeline
{
    /**
     * Rebuild starts_at / ends_at for all timed (non-global) tasks on the event.
     */
    public function recalculate(Event $event): void
    {
        DB::transaction(function () use ($event): void {
            $event->refresh();

            foreach ($this->windows($event) as $window) {
                $window['task']->forceFill([
                    'starts_at' => $window['from']->format('H:i:s'),
                    'ends_at' => $window['to']->format('H:i:s'),
                ])->saveQuietly();
            }
        });
    }

    /**
     * When each timed task runs, in service order. Tasks before the start are counted backwards so the last
     * one ends at the start time; tasks after the start follow each other from the start time.
     *
     * @return Collection<int, Window>
     */
    public function windows(Event $event): Collection
    {
        if ($event->starts_at === null) {
            return collect();
        }

        $start = $event->starts_at->copy()->timezone(config('app.timezone'))->setSeconds(0);
        [$before, $after] = $this->timedTasks($event)->partition(fn (EventTask $task): bool => $task->before_start);

        $beforeWindows = collect();
        $cursor = $start->copy();

        foreach ($before->reverse() as $task) {
            $to = $cursor->copy();
            $cursor = $cursor->copy()->subMinutes($this->minutes($task));
            $beforeWindows->prepend(['from' => $cursor->copy(), 'to' => $to, 'task' => $task]);
        }

        $afterWindows = collect();
        $cursor = $start->copy();

        foreach ($after as $task) {
            $from = $cursor->copy();
            $cursor = $cursor->copy()->addMinutes($this->minutes($task));
            $afterWindows->push(['from' => $from, 'to' => $cursor->copy(), 'task' => $task]);
        }

        return $beforeWindows->concat($afterWindows)->values();
    }

    /**
     * Timed tasks in service order: before-start tasks first, then the rest, each by sort order.
     *
     * @return Collection<int, EventTask>
     */
    public function timedTasks(Event $event): Collection
    {
        return EventTask::query()
            ->where('event_id', $event->id)
            ->with('template')
            ->orderByDesc('before_start')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (EventTask $task): bool => ! $task->isGlobal())
            ->values();
    }

    protected function minutes(EventTask $task): int
    {
        return max(1, (int) ($task->duration ?? 1));
    }
}
