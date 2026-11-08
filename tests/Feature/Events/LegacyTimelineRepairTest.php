<?php

namespace Tests\Feature\Events;

use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Services\EventTaskTimeline;
use App\Services\LegacyTimelineRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyTimelineRepairTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> legacy 25 October service: start time => name */
    private const SERVICE = [
        '09:00:00' => 'Lovsångsövning',
        '10:15:00' => 'Samling',
        '10:30:00' => 'Mingel',
        '11:00:00' => 'Sång A',
        '11:04:00' => 'Välkomsttal',
        '11:07:00' => 'Sång B',
        '11:22:00' => 'Predikan',
        '11:57:00' => 'Kollekt',
        '12:15:00' => 'Avslut',
    ];

    private Church $church;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
    }

    /**
     * @param  array<string, string>  $service
     */
    private function legacyEvent(Church $church, array $service = self::SERVICE, string $start = '2026-10-25 09:00:00'): Event
    {
        $event = Event::factory()->create(['church_id' => $church->id, 'starts_at' => $start]);

        $timed = TaskTemplate::factory()->create(['church_id' => $church->id, 'use_globally' => false]);
        $global = TaskTemplate::factory()->create(['church_id' => $church->id, 'use_globally' => true]);

        EventTask::factory()->create([
            'event_id' => $event->id, 'task_template_id' => $global->id, 'name' => 'Sound',
            'starts_at' => '00:00:01', 'ends_at' => null, 'duration' => 5, 'sort_order' => 4,
        ]);

        foreach (array_reverse($service, true) as $startsAt => $name) {
            EventTask::factory()->create([
                'event_id' => $event->id, 'task_template_id' => $timed->id, 'name' => $name,
                'starts_at' => $startsAt, 'ends_at' => null, 'duration' => 5, 'sort_order' => 0,
            ]);
        }

        return $event;
    }

    /**
     * @return array<string, EventTask>
     */
    private function tasksByName(Event $event): array
    {
        return EventTask::query()->where('event_id', $event->id)->get()->keyBy('name')->all();
    }

    public function test_it_restores_legacy_durations_order_and_end_times_and_the_last_task_keeps_its_duration(): void
    {
        $event = $this->legacyEvent($this->church);
        $repair = app(LegacyTimelineRepair::class);

        $repair->apply($repair->plan($this->church->id));
        $tasks = $this->tasksByName($event);

        $this->assertSame(
            [75, 15, 30, 4, 3, 15, 35, 18, 5],
            collect(self::SERVICE)->map(fn (string $name): int => $tasks[$name]->duration)->values()->all(),
        );
        $this->assertSame(
            range(1, 9),
            collect(self::SERVICE)->map(fn (string $name): int => $tasks[$name]->sort_order)->values()->all(),
        );
        $this->assertSame('10:15:00', $tasks['Lovsångsövning']->ends_at);
        $this->assertSame('12:20:00', $tasks['Avslut']->ends_at);
        $this->assertSame('11:22:00', $tasks['Predikan']->starts_at, 'Stored start times are never shifted.');
    }

    public function test_global_responsibilities_are_not_touched_or_given_timeline_time(): void
    {
        $event = $this->legacyEvent($this->church);
        $repair = app(LegacyTimelineRepair::class);

        $repair->apply($repair->plan($this->church->id));
        $sound = $this->tasksByName($event)['Sound'];

        $this->assertSame(5, $sound->duration);
        $this->assertSame(4, $sound->sort_order);
        $this->assertNull($sound->ends_at);
    }

    public function test_app_recalculation_leaves_a_repaired_timeline_unchanged(): void
    {
        $event = $this->legacyEvent($this->church);
        $repair = app(LegacyTimelineRepair::class);
        $repair->apply($repair->plan($this->church->id));
        $before = EventTask::query()->where('event_id', $event->id)->orderBy('id')->pluck('starts_at', 'id')->all();

        app(EventTaskTimeline::class)->recalculate($event->fresh());

        $this->assertSame($before, EventTask::query()->where('event_id', $event->id)->orderBy('id')->pluck('starts_at', 'id')->all());
    }

    public function test_dry_run_writes_nothing_and_rerun_is_idempotent(): void
    {
        $event = $this->legacyEvent($this->church);
        $repair = app(LegacyTimelineRepair::class);

        $plan = $repair->plan($this->church->id);
        $this->assertSame(1, $plan['totals']['events_to_repair']);
        $this->assertSame(5, $this->tasksByName($event)['Predikan']->duration);

        $this->assertSame(9, $repair->apply($plan));
        $this->assertSame(0, $repair->apply($plan), 'Applying the same plan twice changes nothing.');

        $second = $repair->plan($this->church->id);
        $this->assertSame(0, $second['totals']['events_to_repair']);
        $this->assertSame(1, $second['totals']['events_already_ok']);
    }

    public function test_tasks_sharing_a_start_time_are_reported_and_skipped_until_accepted(): void
    {
        $event = $this->legacyEvent($this->church, ['19:00:00' => 'Predikan', '19:00:00 ' => 'Sång', '19:30:00' => 'Avslut'], '2026-11-01 19:00:00', '2026-11-01 21:00:00');
        EventTask::query()->where('event_id', $event->id)->where('name', 'Sång')->update(['starts_at' => '19:00:00']);
        $repair = app(LegacyTimelineRepair::class);

        $plan = $repair->plan($this->church->id);

        $this->assertSame('skipped', $plan['events'][0]['status']);
        $this->assertContains(LegacyTimelineRepair::FLAG_SAME_START, $plan['events'][0]['flags']);
        $this->assertSame(0, $repair->apply($plan));
        $this->assertSame(5, $this->tasksByName($event)['Predikan']->duration);

        $accepted = $repair->plan($this->church->id, acceptAmbiguous: true);
        $this->assertSame('repair', $accepted['events'][0]['status']);
    }

    public function test_events_already_edited_in_the_app_are_never_overwritten(): void
    {
        $event = $this->legacyEvent($this->church);
        EventTask::query()->where('event_id', $event->id)->where('name', 'Predikan')->update(['ends_at' => '11:50:00', 'duration' => 28]);
        $repair = app(LegacyTimelineRepair::class);

        $plan = $repair->plan($this->church->id, acceptAmbiguous: true);

        $this->assertSame('skipped', $plan['events'][0]['status']);
        $this->assertContains(LegacyTimelineRepair::FLAG_MIXED, $plan['events'][0]['flags']);
        $this->assertSame(0, $repair->apply($plan));
        $this->assertSame(28, $this->tasksByName($event)['Predikan']->duration);
    }

    public function test_repair_is_scoped_to_the_requested_church(): void
    {
        $other = Church::factory()->create();
        $mine = $this->legacyEvent($this->church);
        $theirs = $this->legacyEvent($other);
        $repair = app(LegacyTimelineRepair::class);

        $repair->apply($repair->plan($this->church->id));

        $this->assertSame(75, $this->tasksByName($mine)['Lovsångsövning']->duration);
        $this->assertSame(5, $this->tasksByName($theirs)['Lovsångsövning']->duration);
    }

    public function test_command_is_a_dry_run_by_default_and_writes_a_report_when_applied(): void
    {
        Storage::fake('local');
        $event = $this->legacyEvent($this->church);

        $this->artisan('worship:repair-legacy-timelines', ['--church' => $this->church->id])->assertSuccessful();
        $this->assertSame(5, $this->tasksByName($event)['Predikan']->duration);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->artisan('worship:repair-legacy-timelines', ['--church' => $this->church->id, '--apply' => true])->assertSuccessful();
        $this->assertSame(35, $this->tasksByName($event)['Predikan']->duration);
        $this->assertCount(1, Storage::disk('local')->allFiles('legacy-timeline-repairs'));
    }

    public function test_command_requires_a_church(): void
    {
        $this->artisan('worship:repair-legacy-timelines')->assertFailed();
    }
}
