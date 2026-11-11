<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToChurch;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use BelongsToChurch, HasFactory;

    /**
     * Global scope that keeps service templates out of every normal event query.
     */
    public const SERVICES_SCOPE = 'services';

    protected $fillable = [
        'church_id',
        'name',
        'description',
        'is_template',
        'starts_at',
        'shared_at',
        'plan_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'is_template' => 'boolean',
            'shared_at' => 'datetime',
            'plan_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(self::SERVICES_SCOPE, function (Builder $query): void {
            $query->where($query->getModel()->getTable().'.is_template', false);
        });
    }

    /**
     * Service templates of the current church (they are excluded from normal event queries).
     *
     * @return Builder<Event>
     */
    public static function templates(): Builder
    {
        return static::query()->withoutGlobalScope(self::SERVICES_SCOPE)->where('events.is_template', true);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(EventTask::class)
            ->withoutGlobalScope(EventTask::SERVICES_SCOPE)
            ->orderByRaw('(SELECT COALESCE(use_globally, 0) FROM task_templates WHERE task_templates.id = event_tasks.task_template_id) DESC')
            ->orderByDesc('event_tasks.before_start')
            ->orderByRaw('CASE WHEN (SELECT COALESCE(use_globally, 0) FROM task_templates WHERE task_templates.id = event_tasks.task_template_id) = 1 THEN (SELECT default_order FROM task_templates WHERE task_templates.id = event_tasks.task_template_id) ELSE event_tasks.sort_order END')
            ->orderBy('event_tasks.id');
    }

    /**
     * When the program ends: the start time plus the duration of every timed
     * (non-global) task after the start. Without any this is the start time itself.
     */
    public function endsAt(): ?Carbon
    {
        if ($this->starts_at === null) {
            return null;
        }

        $tasks = $this->relationLoaded('tasks')
            ? $this->tasks
            : $this->tasks()->with('template')->get();

        $minutes = $tasks
            ->reject(fn (EventTask $task): bool => $task->isGlobal() || $task->before_start)
            ->sum(fn (EventTask $task): int => max(1, (int) ($task->duration ?? 1)));

        return $this->starts_at->copy()->setSeconds(0)->addMinutes($minutes);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EventMessage::class)->latest();
    }

    /**
     * Users assigned to at least one task on this event.
     *
     * @return EloquentCollection<int, User>
     */
    public function participantUsers(): EloquentCollection
    {
        $ids = $this->tasks()
            ->whereNotNull('assigned_user_id')
            ->pluck('assigned_user_id')
            ->unique()
            ->filter()
            ->values();

        if ($ids->isEmpty()) {
            return new EloquentCollection;
        }

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('display_name')
            ->orderBy('name')
            ->get();
    }

    /**
     * Teams assigned to at least one task on this event.
     *
     * @return EloquentCollection<int, Team>
     */
    public function participantTeams(): EloquentCollection
    {
        $ids = $this->tasks()
            ->whereNotNull('assigned_team_id')
            ->pluck('assigned_team_id')
            ->unique()
            ->filter()
            ->values();

        if ($ids->isEmpty()) {
            return new EloquentCollection;
        }

        return Team::query()
            ->whereIn('id', $ids)
            ->with('leaders')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int|string, string>
     */
    public function participantOptions(): Collection
    {
        return $this->participantUsers()
            ->mapWithKeys(fn (User $user) => [
                $user->id => $user->display_name ?: $user->name,
            ]);
    }

    /**
     * @return Collection<int|string, string>
     */
    public function participantTeamOptions(): Collection
    {
        return $this->participantTeams()
            ->mapWithKeys(fn (Team $team) => [
                $team->id => $team->name.' (to leaders)',
            ]);
    }

    /**
     * One option per task that has a person: "Anna — Preacher". Keys are task ids.
     *
     * @return Collection<int, string>
     */
    public function personTaskOptions(): Collection
    {
        return $this->tasks()
            ->whereNotNull('assigned_user_id')
            ->with(['assignedUser', 'template'])
            ->get()
            ->sortBy(fn (EventTask $task): string => mb_strtolower($task->assignedUser?->displayName().' '.$task->displayName()))
            ->mapWithKeys(fn (EventTask $task): array => [
                $task->id => $task->assignedUser?->displayName().' — '.$task->displayName(),
            ]);
    }

    /**
     * One option per task that only has a team: "Music team — Worship (to leaders)". Keys are task ids.
     *
     * @return Collection<int, string>
     */
    public function teamTaskOptions(): Collection
    {
        return $this->tasks()
            ->whereNull('assigned_user_id')
            ->whereNotNull('assigned_team_id')
            ->with(['assignedTeam', 'template'])
            ->get()
            ->sortBy(fn (EventTask $task): string => mb_strtolower($task->assignedTeam?->name.' '.$task->displayName()))
            ->mapWithKeys(fn (EventTask $task): array => [
                $task->id => __(':team — :task (to leaders)', [
                    'team' => $task->assignedTeam?->name,
                    'task' => $task->displayName(),
                ]),
            ]);
    }

    /**
     * Tasks the user is assigned to personally first, then tasks for teams they lead.
     *
     * @return Collection<int, EventTask>
     */
    public function tasksFor(User $user): Collection
    {
        $this->loadMissing(['tasks.assignedTeam', 'tasks.responses', 'tasks.template']);

        $personal = $this->tasks->filter(
            fn (EventTask $task): bool => (int) $task->assigned_user_id === (int) $user->id
        );

        $ledTeams = $this->tasks->filter(fn (EventTask $task): bool => blank($task->assigned_user_id)
            && filled($task->assigned_team_id)
            && ($task->assignedTeam?->isLeader($user) ?? false));

        return $personal->concat($ledTeams)->values();
    }
}
