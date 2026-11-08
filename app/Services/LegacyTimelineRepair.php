<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventTask;
use Illuminate\Support\Facades\DB;

/**
 * Repairs timelines imported from the legacy PPP system.
 *
 * Legacy timed activities carry only a wall-clock `start_time`; the end was never stored and `global_order`
 * is 0. The import therefore stored `duration = 5` (template fallback), `ends_at = NULL`, `sort_order = 0`.
 * A legacy-damaged task is recognised by a stored `starts_at` with no `ends_at` (the app always writes both).
 *
 * Policy (never shifts a stored start time):
 * - order: `starts_at`, then `id`
 * - duration: next task's start minus this start; the last task keeps its stored duration (events have no end)
 * - events with ambiguity are reported and left untouched unless explicitly accepted. Ambiguity means tasks
 *   sharing a start time (usually unscheduled placeholders)
 * - events where the app already wrote end times are left untouched (nothing is guessed over real edits)
 */
class LegacyTimelineRepair
{
    public const FLAG_MIXED = 'mixed_repaired_and_unrepaired';

    public const FLAG_MISSING_START = 'timed_task_without_start';

    public const FLAG_SAME_START = 'tasks_share_start_time';

    public const FLAG_LEADING_OFFSET = 'first_task_not_at_event_start';

    /** Flags that always block an event; the others block unless ambiguity is accepted. */
    private const BLOCKING = [self::FLAG_MIXED, self::FLAG_MISSING_START];

    /** Informational only. */
    private const INFORMATIONAL = [self::FLAG_LEADING_OFFSET];

    /**
     * @return array{church_id: int, accept_ambiguous: bool, events: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function plan(int $churchId, bool $acceptAmbiguous = false, ?int $eventId = null): array
    {
        $events = Event::withoutGlobalScopes()
            ->where('church_id', $churchId)
            ->when($eventId !== null, fn ($query) => $query->whereKey($eventId))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $plans = [];
        $totals = ['events_examined' => 0, 'events_to_repair' => 0, 'events_skipped' => 0, 'events_already_ok' => 0, 'tasks_to_update' => 0];

        foreach ($events as $event) {
            $totals['events_examined']++;
            $eventPlan = $this->planEvent($event, $acceptAmbiguous);

            if ($eventPlan['status'] === 'already_ok') {
                $totals['events_already_ok']++;

                continue;
            }

            $totals[$eventPlan['status'] === 'repair' ? 'events_to_repair' : 'events_skipped']++;
            $totals['tasks_to_update'] += $eventPlan['status'] === 'repair' ? count($eventPlan['tasks']) : 0;
            $plans[] = $eventPlan;
        }

        return [
            'church_id' => $churchId,
            'accept_ambiguous' => $acceptAmbiguous,
            'events' => $plans,
            'totals' => $totals,
        ];
    }

    /**
     * Writes the planned repair. Safe to rerun: repaired tasks no longer match the legacy marker.
     *
     * @param  array{events: list<array<string, mixed>>}  $plan
     */
    public function apply(array $plan): int
    {
        $updated = 0;

        DB::transaction(function () use ($plan, &$updated): void {
            foreach ($plan['events'] as $eventPlan) {
                if ($eventPlan['status'] !== 'repair') {
                    continue;
                }

                foreach ($eventPlan['tasks'] as $change) {
                    $updated += EventTask::query()
                        ->whereKey($change['id'])
                        ->where('event_id', $eventPlan['event_id'])
                        ->whereNull('ends_at')
                        ->update([
                            'duration' => $change['after']['duration'],
                            'ends_at' => $change['after']['ends_at'],
                            'sort_order' => $change['after']['sort_order'],
                        ]);
                }
            }
        });

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function planEvent(Event $event, bool $acceptAmbiguous): array
    {
        $timed = EventTask::query()
            ->where('event_id', $event->id)
            ->with('template')
            ->orderBy('id')
            ->get()
            ->filter(fn (EventTask $task): bool => ! $task->isGlobal())
            ->values();

        $legacy = $timed->filter(fn (EventTask $task): bool => filled($task->starts_at) && blank($task->ends_at));
        $base = [
            'event_id' => $event->id,
            'name' => $event->name,
            'starts_at' => $event->starts_at?->toDateTimeString(),
            'flags' => [],
            'tasks' => [],
        ];

        if ($legacy->isEmpty()) {
            return $base + ['status' => 'already_ok'];
        }

        $flags = [];

        if ($legacy->count() !== $timed->count()) {
            $flags[] = $timed->contains(fn (EventTask $task): bool => blank($task->starts_at))
                ? self::FLAG_MISSING_START
                : self::FLAG_MIXED;
        }

        $ordered = $legacy
            ->sortBy([fn (EventTask $a, EventTask $b): int => $this->seconds($a->starts_at) <=> $this->seconds($b->starts_at), ['id', 'asc']])
            ->values();

        if ($ordered->pluck('starts_at')->duplicates()->isNotEmpty()) {
            $flags[] = self::FLAG_SAME_START;
        }

        if ($event->starts_at !== null && $this->seconds($ordered->first()->starts_at) !== $this->seconds($event->starts_at->format('H:i:s'))) {
            $flags[] = self::FLAG_LEADING_OFFSET;
        }

        $flags = array_values(array_unique($flags));
        $blocking = array_values(array_filter($flags, fn (string $flag): bool => ! in_array($flag, self::INFORMATIONAL, true)));
        $isBlocked = array_intersect($flags, self::BLOCKING) !== []
            || ($blocking !== [] && ! $acceptAmbiguous);

        $result = $base;
        $result['flags'] = $flags;
        $result['status'] = $isBlocked ? 'skipped' : 'repair';

        $count = $ordered->count();
        foreach ($ordered as $index => $task) {
            $start = $this->seconds($task->starts_at);
            $nextStart = $index + 1 < $count ? $this->seconds($ordered[$index + 1]->starts_at) : null;
            $usable = $nextStart !== null && $nextStart > $start;
            $minutes = $usable ? max(1, (int) round(($nextStart - $start) / 60)) : max(1, (int) $task->duration);
            $endSeconds = $usable ? $nextStart : $start + $minutes * 60;

            $result['tasks'][] = [
                'id' => $task->id,
                'name' => $task->name ?: $task->template?->name,
                'starts_at' => $task->starts_at,
                'before' => [
                    'duration' => $task->duration,
                    'ends_at' => $task->ends_at,
                    'sort_order' => $task->sort_order,
                ],
                'after' => [
                    'duration' => $minutes,
                    'ends_at' => gmdate('H:i:s', $endSeconds % 86400),
                    'sort_order' => $index + 1,
                ],
                'inferred' => $usable ? 'next_start' : 'kept_existing_duration',
            ];
        }

        return $result;
    }

    private function seconds(?string $time): int
    {
        [$h, $m, $s] = array_map('intval', array_pad(explode(':', (string) $time), 3, '0'));

        return $h * 3600 + $m * 60 + $s;
    }
}
