<?php

namespace Tests\Feature\Events;

use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\AssignmentRequiredWindow;
use App\Services\EventTaskTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AssignmentRequiredWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_timed_task_window_follows_program_chain_not_full_event(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create([
            'church_id' => $church->id,
            'starts_at' => '2026-11-01 10:00:00',
        ]);
        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'use_globally' => false,
            'default_duration' => 10,
        ]);

        $first = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'duration' => 10,
            'sort_order' => 1,
        ]);
        $second = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'duration' => 15,
            'sort_order' => 2,
        ]);

        app(EventTaskTimeline::class)->recalculate($event);

        $windows = app(AssignmentRequiredWindow::class);
        $firstWindow = $windows->forTask($first->fresh(), $event->fresh());
        $secondWindow = $windows->forTask($second->fresh(), $event->fresh());

        $this->assertSame('10:00', $firstWindow['from']->format('H:i'));
        $this->assertSame('10:10', $firstWindow['to']->format('H:i'));
        $this->assertSame('10:10', $secondWindow['from']->format('H:i'));
        $this->assertSame('10:25', $secondWindow['to']->format('H:i'));
    }

    public function test_sequential_dual_roles_are_not_overlapping(): void
    {
        $church = Church::factory()->create();
        $person = User::factory()->create(['church_id' => $church->id]);
        $event = Event::factory()->create([
            'church_id' => $church->id,
            'starts_at' => '2026-11-01 10:00:00',
        ]);
        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'use_globally' => false,
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $person->id,
            'name' => 'Welcome',
            'duration' => 30,
            'sort_order' => 1,
        ]);
        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $person->id,
            'name' => 'Reading',
            'duration' => 20,
            'sort_order' => 2,
        ]);

        app(EventTaskTimeline::class)->recalculate($event);

        $this->assertSame(
            [],
            app(AssignmentRequiredWindow::class)->overlappingPersonCommitments($event->fresh())
        );
    }

    public function test_overlapping_windows_for_same_person_are_reported(): void
    {
        $church = Church::factory()->create();
        $person = User::factory()->create(['church_id' => $church->id]);
        $event = Event::factory()->create([
            'church_id' => $church->id,
            'starts_at' => '2026-11-01 10:00:00',
        ]);
        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'use_globally' => false,
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $person->id,
            'name' => 'A',
            'duration' => 30,
            'sort_order' => 1,
        ]);
        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $person->id,
            'name' => 'B',
            'duration' => 30,
            'sort_order' => 2,
        ]);

        $service = new class(app(EventTaskTimeline::class)) extends AssignmentRequiredWindow
        {
            public function timedWindows(Event $event): Collection
            {
                $tasks = $event->tasks()->orderBy('sort_order')->get();

                return collect([
                    [
                        'from' => $event->starts_at->copy(),
                        'to' => $event->starts_at->copy()->addMinutes(30),
                        'task' => $tasks[0],
                    ],
                    [
                        'from' => $event->starts_at->copy()->addMinutes(15),
                        'to' => $event->starts_at->copy()->addMinutes(45),
                        'task' => $tasks[1],
                    ],
                ]);
            }
        };

        $overlaps = $service->overlappingPersonCommitments($event->fresh(['tasks']));

        $this->assertCount(1, $overlaps);
        $this->assertSame((int) $person->id, $overlaps[0]['user_id']);
        $this->assertEqualsCanonicalizing(['A', 'B'], $overlaps[0]['tasks']);
    }
}
