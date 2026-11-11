<?php

namespace App\Models;

use Database\Factories\EventTaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

class EventTask extends Model
{
    /** @use HasFactory<EventTaskFactory> */
    use HasFactory;

    /**
     * Global scope that keeps the tasks of service templates out of every normal task query.
     */
    public const SERVICES_SCOPE = 'services';

    protected $fillable = [
        'event_id',
        'task_template_id',
        'assigned_user_id',
        'assigned_team_id',
        'person_text',
        'team_text',
        'guest_person',
        'guest_team',
        'accepted_verbally_at',
        'accepted_verbally_by_user_id',
        'name',
        'description',
        'song',
        'song_id',
        'special_needs',
        'bible_passages',
        'duration',
        'starts_at',
        'ends_at',
        'sort_order',
        'before_start',
    ];

    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'sort_order' => 'integer',
            'before_start' => 'boolean',
            'guest_person' => 'boolean',
            'guest_team' => 'boolean',
            'bible_passages' => 'array',
            'accepted_verbally_at' => 'datetime',
        ];
    }

    /**
     * A verbal acceptance belongs to the person or team it was given by.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(self::SERVICES_SCOPE, function (Builder $query): void {
            $query->whereExists(fn (QueryBuilder $events) => $events
                ->selectRaw('1')
                ->from('events')
                ->whereColumn('events.id', $query->getModel()->getTable().'.event_id')
                ->where('events.is_template', false));
        });

        static::saving(function (EventTask $task): void {
            $reassigned = $task->isDirty('assigned_user_id') || $task->isDirty('assigned_team_id');

            if ($task->exists && $reassigned && ! $task->isDirty('accepted_verbally_at')) {
                $task->accepted_verbally_at = null;
                $task->accepted_verbally_by_user_id = null;
            }
        });
    }

    /**
     * The name of a guest picked as the person: someone who is not in the app.
     */
    public function typedPersonName(): ?string
    {
        return $this->hasGuestPerson() && filled($this->person_text) ? (string) $this->person_text : null;
    }

    /**
     * The name of a guest team picked as the team: a team that is not in the app.
     */
    public function typedTeamName(): ?string
    {
        return $this->hasGuestTeam() && filled($this->team_text) ? (string) $this->team_text : null;
    }

    public function hasGuestPerson(): bool
    {
        return $this->guest_person && blank($this->assigned_user_id);
    }

    public function hasGuestTeam(): bool
    {
        return $this->guest_team && blank($this->assigned_team_id);
    }

    /**
     * "Guest" was picked as the person but the guest's name is still missing.
     */
    public function isMissingGuestName(): bool
    {
        return $this->hasGuestPerson() && blank($this->person_text);
    }

    /**
     * "Guest" was picked as the team but the guest team's name is still missing.
     */
    public function isMissingGuestTeamName(): bool
    {
        return $this->hasGuestTeam() && blank($this->team_text);
    }

    /**
     * Only a typed name fills this task: there is nobody in the app to invite or message.
     */
    public function hasOnlyTypedNames(): bool
    {
        return blank($this->assigned_user_id)
            && blank($this->assigned_team_id)
            && ($this->typedPersonName() !== null || $this->typedTeamName() !== null);
    }

    public function isAcceptedVerbally(): bool
    {
        return $this->accepted_verbally_at !== null
            && (filled($this->assigned_user_id) || filled($this->assigned_team_id));
    }

    /**
     * The event or service template this task belongs to.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withoutGlobalScope(Event::SERVICES_SCOPE);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class, 'task_template_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'assigned_team_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(EventTaskResponse::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function catalogSong(): BelongsTo
    {
        return $this->belongsTo(Song::class, 'song_id');
    }

    public function pictures(): BelongsToMany
    {
        return $this->belongsToMany(Picture::class)
            ->withPivot(['sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function sharedFiles(): BelongsToMany
    {
        return $this->belongsToMany(SharedFile::class)
            ->withPivot(['sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function displayName(): string
    {
        return $this->name ?: ($this->template?->name ?? 'Task');
    }

    public function isGlobal(): bool
    {
        return (bool) ($this->template?->use_globally ?? false);
    }
}
