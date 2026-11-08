<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventCopier
{
    /**
     * @param  array{
     *     name?: string|null,
     *     description?: string|null,
     *     starts_at: Carbon|string,
     *     weeks?: int,
     *     copy_people_and_teams?: bool,
     *     copy_songs?: bool
     * }  $options
     * @return Collection<int, Event>
     */
    public function copy(Event $source, array $options): Collection
    {
        $startsAt = Carbon::parse($options['starts_at']);
        $weeks = max(1, (int) ($options['weeks'] ?? 1));
        $name = array_key_exists('name', $options)
            ? (string) $options['name']
            : $source->name;
        $description = array_key_exists('description', $options)
            ? $options['description']
            : $source->description;
        $copyPeopleAndTeams = (bool) ($options['copy_people_and_teams'] ?? false);
        $copySongs = (bool) ($options['copy_songs'] ?? false);

        return DB::transaction(function () use (
            $source,
            $startsAt,
            $weeks,
            $name,
            $description,
            $copyPeopleAndTeams,
            $copySongs,
        ) {
            $source->loadMissing(['tasks.pictures', 'tasks.sharedFiles']);

            $created = collect();

            for ($offset = 0; $offset < $weeks; $offset++) {
                $event = Event::query()->create([
                    'church_id' => $source->church_id,
                    'name' => $name,
                    'description' => $description,
                    'starts_at' => $startsAt->copy()->addWeeks($offset),
                ]);

                foreach ($source->tasks as $task) {
                    $copy = EventTask::query()->create([
                        'event_id' => $event->id,
                        'task_template_id' => $task->task_template_id,
                        'assigned_user_id' => $copyPeopleAndTeams ? $task->assigned_user_id : null,
                        'assigned_team_id' => $copyPeopleAndTeams ? $task->assigned_team_id : null,
                        'name' => $task->name,
                        'description' => $task->description,
                        'song' => $copySongs ? $task->song : null,
                        'song_id' => $copySongs ? $task->song_id : null,
                        'special_needs' => $task->special_needs,
                        'bible_passages' => $task->bible_passages,
                        'duration' => $task->duration,
                        'starts_at' => $task->starts_at,
                        'ends_at' => $task->ends_at,
                        'sort_order' => $task->sort_order,
                    ]);

                    $pictureSync = [];
                    foreach ($task->pictures as $index => $picture) {
                        $pictureSync[$picture->id] = [
                            'sort_order' => $picture->pivot->sort_order ?? $index,
                        ];
                    }

                    if ($pictureSync !== []) {
                        $copy->pictures()->sync($pictureSync);
                    }

                    $fileSync = [];
                    foreach ($task->sharedFiles as $index => $file) {
                        $fileSync[$file->id] = [
                            'sort_order' => $file->pivot->sort_order ?? $index,
                        ];
                    }

                    if ($fileSync !== []) {
                        $copy->sharedFiles()->sync($fileSync);
                    }
                }

                $created->push($event->fresh(['tasks']));
            }

            return $created;
        });
    }
}
