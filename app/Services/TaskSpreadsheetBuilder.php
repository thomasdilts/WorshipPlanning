<?php

namespace App\Services;

use App\Enums\AssignmentState;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\Unavailability;
use App\Support\DateTimeFormats;
use App\Support\UserAvatar;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Unavailability\Services\UnavailabilityConflictService;

class TaskSpreadsheetBuilder
{
    public function __construct(
        protected UnavailabilityConflictService $unavailability,
        protected AssignmentStateResolver $states,
    ) {}

    /**
     * @param  list<int>|null  $myTeamIds
     * @return array{
     *     staffing_columns: list<array{id: int, name: string, key: string}>,
     *     extra_columns: list<array{id: int, name: string, key: string}>,
     *     rows: Collection<string, array<string, mixed>>
     * }
     */
    public function build(
        Church $church,
        Carbon|string|null $from = null,
        Carbon|string|null $until = null,
        ?array $myTeamIds = null,
    ): array {
        $fromDate = Carbon::parse($from ?? now())->startOfDay();
        $untilDate = $until !== null ? Carbon::parse($until)->endOfDay() : null;
        $myTeamIds = collect($myTeamIds ?? [])->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();

        $eventsQuery = Event::query()
            ->where('church_id', $church->id)
            ->whereDate('starts_at', '>=', $fromDate->toDateString());

        if ($untilDate !== null) {
            $eventsQuery->whereDate('starts_at', '<=', $untilDate->toDateString());
        }

        $events = $eventsQuery
            ->with([
                'tasks.template',
                'tasks.assignedUser',
                'tasks.assignedTeam',
                'tasks.responses',
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $usedTemplateIds = $events
            ->flatMap(fn (Event $event) => $event->tasks->pluck('task_template_id'))
            ->unique()
            ->filter()
            ->values();

        $templates = TaskTemplate::query()
            ->where('church_id', $church->id)
            ->whereIn('id', $usedTemplateIds)
            ->orderByRaw('CASE WHEN use_globally = 1 THEN 0 ELSE 1 END')
            ->orderBy('default_global_order')
            ->orderBy('default_order')
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $staffingColumns = [];
        $extraColumns = [];

        foreach ($templates as $template) {
            if ($template->requiresPersonOrTeam()) {
                $staffingColumns[] = $this->columnMeta($template, 'template_');
            }
        }

        foreach ($templates as $template) {
            if ($template->show_in_spreadsheet) {
                $extraColumns[] = $this->columnMeta($template, 'spreadsheet_');
            }
        }

        $unavailableByEvent = $this->unavailabilityEnabled($church)
            ? $this->subjectsUnavailableByEvent($church, $events)
            : collect();

        $states = $this->states->forMany(new EloquentCollection($events->flatMap->tasks->all()));

        $eventTones = $events->pluck('name')->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->flip();

        $rows = $events->mapWithKeys(function (Event $event) use ($staffingColumns, $extraColumns, $unavailableByEvent, $states, $myTeamIds, $eventTones): array {
            $tasksByTemplate = $event->tasks->groupBy('task_template_id');
            $unavailable = $unavailableByEvent->get($event->id, $this->emptyUnavailable());

            $row = [
                'key' => 'event:'.$event->id,
                'event_id' => $event->id,
                'event_date' => $event->starts_at?->toDateString(),
                'event_date_label' => $event->starts_at?->translatedFormat(DateTimeFormats::dateWithWeekday()),
                'event_calendar_label' => $event->starts_at?->translatedFormat(DateTimeFormats::date()),
                'event_day_label' => $event->starts_at?->translatedFormat('l'),
                'event_week' => $event->starts_at?->isoWeek(),
                'event_tone' => $eventTones->get($event->name, 0) % 3,
                'event_time_label' => DateTimeFormats::formatTime($event->starts_at),
                'event_name' => $event->name,
                'needs_attention' => false,
                'my_team_needs' => false,
            ];

            foreach ($staffingColumns as $column) {
                $cell = $this->staffingCell(
                    $tasksByTemplate->get($column['id']) ?? collect(),
                    $unavailable,
                    $states,
                    $myTeamIds,
                );
                $row[$column['key']] = $cell['label'];
                $row[$column['key'].'_task_id'] = $cell['task_id'];
                $row[$column['key'].'_task_ids'] = $cell['task_ids'];
                $row[$column['key'].'_vacant'] = $cell['vacant'];
                $row[$column['key'].'_state'] = $cell['state']?->value;
                $row[$column['key'].'_conflict'] = $cell['conflict'];
                $row[$column['key'].'_needs_attention'] = $cell['needs_attention'];
                $row[$column['key'].'_my_team'] = $cell['my_team'];

                if ($cell['needs_attention'] || $cell['conflict']) {
                    $row['needs_attention'] = true;
                }

                if ($cell['my_team'] && ($cell['needs_attention'] || $cell['conflict'] || $cell['vacant'])) {
                    $row['my_team_needs'] = true;
                }
            }

            foreach ($extraColumns as $column) {
                $row[$column['key']] = $this->extraCell($tasksByTemplate->get($column['id']) ?? collect());
            }

            $row['unavailable'] = $unavailable['names']->implode(', ');

            return [$row['key'] => $row];
        });

        return [
            'staffing_columns' => $staffingColumns,
            'extra_columns' => $extraColumns,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{id: int, name: string, key: string}
     */
    protected function columnMeta(TaskTemplate $template, string $keyPrefix = 'template_'): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'key' => $keyPrefix.$template->id,
        ];
    }

    /**
     * @param  Collection<int, EventTask>  $tasks
     * @param  array{names: Collection<int, string>, user_ids: Collection<int, int>, team_ids: Collection<int, int>}  $unavailable
     * @param  array<int, AssignmentState>  $states
     * @param  Collection<int, int>  $myTeamIds
     * @return array{
     *     label: string,
     *     task_id: int|null,
     *     task_ids: list<int>,
     *     vacant: bool,
     *     state: AssignmentState|null,
     *     conflict: bool,
     *     needs_attention: bool,
     *     my_team: bool
     * }
     */
    protected function staffingCell(Collection $tasks, array $unavailable, array $states, Collection $myTeamIds): array
    {
        if ($tasks->isEmpty()) {
            return [
                'label' => '',
                'task_id' => null,
                'task_ids' => [],
                'vacant' => false,
                'state' => null,
                'conflict' => false,
                'needs_attention' => false,
                'my_team' => false,
            ];
        }

        $taskIds = $tasks
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        /** @var EventTask $primary */
        $primary = $tasks->first();

        $labels = $tasks
            ->map(function (EventTask $task) use ($tasks): string {
                $bits = [];
                $title = ($tasks->count() > 1 && filled($task->name)) ? e((string) $task->name) : null;

                if ($task->assignedUser) {
                    $bits[] = UserAvatar::html($task->assignedUser, size: 'sm')->toHtml();
                }

                if ($task->typedPersonName() !== null) {
                    $bits[] = e($task->typedPersonName());
                }

                if ($task->assignedTeam) {
                    $bits[] = e($task->assignedTeam->name);
                }

                if ($task->typedTeamName() !== null) {
                    $bits[] = e($task->typedTeamName());
                }

                $assignee = implode(' / ', array_filter($bits));

                if ($title && $assignee !== '') {
                    return $title.': '.$assignee;
                }

                return $title ?: $assignee;
            })
            ->filter()
            ->unique()
            ->values();

        $conflict = $tasks->contains(function (EventTask $task) use ($unavailable): bool {
            $userConflict = filled($task->assigned_user_id)
                && $unavailable['user_ids']->contains((int) $task->assigned_user_id);
            $teamConflict = filled($task->assigned_team_id)
                && $unavailable['team_ids']->contains((int) $task->assigned_team_id);

            return $userConflict || $teamConflict;
        });

        $state = $states[$primary->id] ?? AssignmentState::Vacant;
        $vacant = $labels->isEmpty();
        $needsAttention = $vacant || $state->needsAttention() || $conflict;

        $myTeam = $tasks->contains(function (EventTask $task) use ($myTeamIds): bool {
            return filled($task->assigned_team_id)
                && $myTeamIds->contains((int) $task->assigned_team_id);
        });

        return [
            'label' => $labels->implode('; ') ?: "\u{00A0}",
            'task_id' => (int) $primary->id,
            'task_ids' => $taskIds,
            'vacant' => $vacant,
            'state' => $state,
            'conflict' => $conflict,
            'needs_attention' => $needsAttention,
            'my_team' => $myTeam,
        ];
    }

    /**
     * Event-task description only (not the template default description).
     *
     * @param  Collection<int, EventTask>  $tasks
     */
    protected function extraCell(Collection $tasks): string
    {
        if ($tasks->isEmpty()) {
            return '';
        }

        return $tasks
            ->map(function (EventTask $task): string {
                if (! filled($task->description)) {
                    return '';
                }

                return trim(strip_tags((string) $task->description));
            })
            ->filter()
            ->unique()
            ->implode('; ');
    }

    protected function unavailabilityEnabled(Church $church): bool
    {
        return $this->unavailability->enabledFor($church);
    }

    /**
     * @return array{names: Collection<int, string>, user_ids: Collection<int, int>, team_ids: Collection<int, int>}
     */
    protected function emptyUnavailable(): array
    {
        return [
            'names' => collect(),
            'user_ids' => collect(),
            'team_ids' => collect(),
        ];
    }

    /**
     * People and teams unavailable overlapping each event window.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, array{names: Collection<int, string>, user_ids: Collection<int, int>, team_ids: Collection<int, int>}>
     */
    protected function subjectsUnavailableByEvent(Church $church, Collection $events): Collection
    {
        if ($events->isEmpty()) {
            return collect();
        }

        $rangeStart = $events->min('starts_at');
        $endsAtByEvent = $events->mapWithKeys(fn (Event $event): array => [$event->id => $event->endsAt()]);
        $rangeEnd = $endsAtByEvent->max();

        $records = Unavailability::query()
            ->where('church_id', $church->id)
            ->overlapping($rangeStart, $rangeEnd)
            ->with(['user', 'team'])
            ->get();

        $byEvent = collect();

        foreach ($events as $event) {
            $matching = $records
                ->filter(fn (Unavailability $record) => $record->overlapsRange($event->starts_at, $endsAtByEvent[$event->id]));

            $byEvent->put($event->id, [
                'names' => $matching
                    ->map(fn (Unavailability $record) => $record->subjectLabel())
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values(),
                'user_ids' => $matching
                    ->pluck('user_id')
                    ->filter()
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values(),
                'team_ids' => $matching
                    ->pluck('team_id')
                    ->filter()
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values(),
            ]);
        }

        return $byEvent;
    }
}
