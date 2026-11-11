<?php

namespace Tests\Feature\Events;

use App\Enums\FieldUsage;
use App\Filament\Resources\Events\Schemas\EventTaskForm;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Services\EventTaskTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTaskTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_recalculate_chains_start_and_end_from_durations(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create([
            'church_id' => $church->id,
            'starts_at' => '2026-11-01 10:00:00',
        ]);

        $t1 = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'use_globally' => false,
            'default_duration' => 10,
            'person_usage' => FieldUsage::Optional,
        ]);
        $t2 = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'use_globally' => false,
            'default_duration' => 15,
        ]);
        $t3 = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'use_globally' => false,
            'default_duration' => 5,
        ]);

        $a = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $t1->id,
            'duration' => 10,
            'sort_order' => 1,
        ]);
        $b = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $t2->id,
            'duration' => 15,
            'sort_order' => 2,
        ]);
        $c = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $t3->id,
            'duration' => 5,
            'sort_order' => 3,
        ]);

        app(EventTaskTimeline::class)->recalculate($event);

        $a->refresh();
        $b->refresh();
        $c->refresh();

        $this->assertSame('10:00:00', $a->starts_at);
        $this->assertSame('10:10:00', $a->ends_at);
        $this->assertSame('10:10:00', $b->starts_at);
        $this->assertSame('10:25:00', $b->ends_at);
        $this->assertSame('10:25:00', $c->starts_at);
        $this->assertSame('10:30:00', $c->ends_at);
    }

    public function test_changing_duration_via_persist_shifts_following_tasks(): void
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
            'duration' => 10,
            'sort_order' => 2,
        ]);

        app(EventTaskTimeline::class)->recalculate($event);

        EventTaskForm::persist($first, [
            'task_template_id' => $template->id,
            'duration' => 20,
            'sort_order' => 1,
        ], $event);

        $first->refresh();
        $second->refresh();

        $this->assertSame(20, $first->duration);
        $this->assertSame('10:00:00', $first->starts_at);
        $this->assertSame('10:20:00', $first->ends_at);
        $this->assertSame('10:20:00', $second->starts_at);
        $this->assertSame('10:30:00', $second->ends_at);
    }

    public function test_global_tasks_are_skipped_on_timeline(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create([
            'church_id' => $church->id,
            'starts_at' => '2026-11-01 10:00:00',
        ]);
        $global = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'use_globally' => true,
            'default_order' => 1,
        ]);
        $timed = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'use_globally' => false,
            'default_duration' => 10,
        ]);

        $g = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $global->id,
            'duration' => null,
            'sort_order' => 0,
        ]);
        $t = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $timed->id,
            'duration' => 10,
            'sort_order' => 1,
        ]);

        app(EventTaskTimeline::class)->recalculate($event);

        $g->refresh();
        $t->refresh();

        $this->assertNull($g->starts_at);
        $this->assertNull($g->ends_at);
        $this->assertSame('10:00:00', $t->starts_at);
        $this->assertSame('10:10:00', $t->ends_at);
    }
}
