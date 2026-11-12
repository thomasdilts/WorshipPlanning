<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToChurch;
use App\Enums\FieldUsage;
use App\Services\EventTaskTimeline;
use Database\Factories\TaskTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskTemplate extends Model
{
    /** @use HasFactory<TaskTemplateFactory> */
    use BelongsToChurch, HasFactory;

    protected $fillable = [
        'church_id',
        'name',
        'description',
        'default_order',
        'default_duration',
        'use_globally',
        'default_global_order',
        'person_usage',
        'team_usage',
        'team_type_id',
        'description_usage',
        'special_needs_usage',
        'song_usage',
        'allow_manual_song_entry',
        'picture_usage',
        'file_usage',
        'bible_usage',
        'is_active',
        'show_in_spreadsheet',
        'receives_decline_messages',
    ];

    protected function casts(): array
    {
        return [
            'default_order' => 'integer',
            'default_duration' => 'integer',
            'default_global_order' => 'integer',
            'use_globally' => 'boolean',
            'person_usage' => FieldUsage::class,
            'team_usage' => FieldUsage::class,
            'description_usage' => FieldUsage::class,
            'special_needs_usage' => FieldUsage::class,
            'song_usage' => FieldUsage::class,
            'allow_manual_song_entry' => 'boolean',
            'picture_usage' => FieldUsage::class,
            'file_usage' => FieldUsage::class,
            'bible_usage' => FieldUsage::class,
            'is_active' => 'boolean',
            'show_in_spreadsheet' => 'boolean',
            'receives_decline_messages' => 'boolean',
        ];
    }

    public function requiresPersonOrTeam(): bool
    {
        return $this->usage('person')->isUsed() || $this->usage('team')->isUsed();
    }

    /**
     * Deleting a template removes its task from every service and service template that uses it
     * (answers, response links and attached pictures/files go with it), then re-times those plans.
     */
    protected static function booted(): void
    {
        // One "Default order" for every template; the older global order column follows it for exports and imports.
        static::saving(function (TaskTemplate $template): void {
            $template->default_global_order = (int) $template->default_order;
        });

        static::deleting(function (TaskTemplate $template): void {
            $tasks = EventTask::query()
                ->withoutGlobalScope(EventTask::SERVICES_SCOPE)
                ->where('task_template_id', $template->id);
            $eventIds = (clone $tasks)->distinct()->pluck('event_id');

            $tasks->delete();

            Event::query()
                ->withoutGlobalScope(Event::SERVICES_SCOPE)
                ->whereKey($eventIds)
                ->with('tasks.template')
                ->get()
                ->each(fn (Event $event) => app(EventTaskTimeline::class)->recalculate($event));
        });
    }

    public function eventTasks(): HasMany
    {
        return $this->hasMany(EventTask::class);
    }

    /**
     * How many services and service templates have a task made from this template.
     */
    public function usedInEventsCount(): int
    {
        return EventTask::query()
            ->withoutGlobalScope(EventTask::SERVICES_SCOPE)
            ->where('task_template_id', $this->id)
            ->distinct()
            ->count('event_id');
    }

    /**
     * Users approved to be assigned as the person for this template.
     *
     * @return BelongsToMany<User, $this>
     */
    public function ableUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps();
    }

    public function teamType(): BelongsTo
    {
        return $this->belongsTo(TeamType::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function usage(string $field): FieldUsage
    {
        return match ($field) {
            'person' => $this->person_usage ?? FieldUsage::Optional,
            'team' => $this->team_usage ?? FieldUsage::NotUsed,
            'description' => $this->description_usage ?? FieldUsage::Optional,
            'special_needs' => $this->special_needs_usage ?? FieldUsage::Optional,
            'song' => $this->song_usage ?? FieldUsage::NotUsed,
            'picture' => $this->picture_usage ?? FieldUsage::NotUsed,
            'file' => $this->file_usage ?? FieldUsage::NotUsed,
            'bible' => $this->bible_usage ?? FieldUsage::NotUsed,
            default => FieldUsage::NotUsed,
        };
    }
}
