<?php

namespace Tests\Feature\Events;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventTemplateCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_blank_event_defaults_to_today_at_eleven(): void
    {
        $this->travelTo('2026-11-11 16:37:00');
        $church = $this->signInPlanner();

        Livewire::test(CreateEvent::class)
            ->assertFormSet(['starts_at' => '2026-11-11', 'start_time' => '11:00'])
            ->fillForm(['name' => 'Morning worship'])
            ->call('create')
            ->assertHasNoFormErrors();

        $event = Event::query()->sole();
        $this->assertSame($church->id, $event->church_id);
        $this->assertSame('2026-11-11 11:00:00', $event->starts_at->toDateTimeString());
        $this->assertSame(0, $event->tasks()->count());
    }

    public function test_selecting_and_switching_templates_fills_name_and_time_without_changing_the_chosen_date(): void
    {
        $church = $this->signInPlanner();
        $morning = Event::factory()->create(['church_id' => $church->id, 'is_template' => true, 'name' => 'Morning worship', 'starts_at' => '2026-01-04 10:30:00']);
        $evening = Event::factory()->create(['church_id' => $church->id, 'is_template' => true, 'name' => 'Evening worship', 'starts_at' => '2026-01-04 18:45:00']);

        Livewire::test(CreateEvent::class)
            ->fillForm(['starts_at' => '2026-11-22'])
            ->fillForm(['service_template_id' => $morning->id])
            ->assertFormSet(['name' => 'Morning worship', 'start_time' => '10:30', 'starts_at' => '2026-11-22'])
            ->fillForm(['service_template_id' => $evening->id])
            ->assertFormSet(['name' => 'Evening worship', 'start_time' => '18:45', 'starts_at' => '2026-11-22']);
    }

    public function test_creating_from_a_template_copies_the_service_order_and_keeps_manual_overrides(): void
    {
        $church = $this->signInPlanner();
        $source = Event::factory()->create(['church_id' => $church->id, 'is_template' => true, 'name' => 'Sunday template', 'starts_at' => '2026-01-04 10:00:00']);
        $person = User::factory()->create(['church_id' => $church->id]);
        $activity = TaskTemplate::factory()->create(['church_id' => $church->id, 'use_globally' => false]);
        EventTask::factory()->create(['event_id' => $source->id, 'task_template_id' => $activity->id, 'assigned_user_id' => $person->id, 'song' => 'Amazing Grace', 'duration' => 10, 'sort_order' => 1]);

        Livewire::test(CreateEvent::class)
            ->fillForm(['service_template_id' => $source->id])
            ->fillForm(['name' => 'Christmas worship', 'description' => 'An evening service', 'starts_at' => '2026-12-24', 'start_time' => '18:17'])
            ->call('create')
            ->assertHasNoFormErrors();

        $event = Event::query()->sole();
        $this->assertSame('Christmas worship', $event->name);
        $this->assertSame('An evening service', $event->description);
        $this->assertSame('2026-12-24 18:17:00', $event->starts_at->toDateTimeString());
        $this->assertFalse($event->is_template);
        $task = $event->tasks()->sole();
        $this->assertSame($person->id, $task->assigned_user_id);
        $this->assertSame('Amazing Grace', $task->song);
        $this->assertSame('18:17:00', $task->starts_at);
        $this->assertSame('Sunday template', $source->fresh()->name);
    }

    public function test_copy_options_appear_only_after_choosing_a_template(): void
    {
        $church = $this->signInPlanner();
        $source = Event::factory()->create(['church_id' => $church->id, 'is_template' => true]);

        Livewire::test(CreateEvent::class)
            ->assertFormFieldHidden('weeks')
            ->assertFormFieldHidden('copy_people_and_teams')
            ->assertFormFieldHidden('copy_songs')
            ->fillForm(['service_template_id' => $source->id])
            ->assertFormFieldVisible('weeks')
            ->assertFormSet(['weeks' => 1, 'copy_people_and_teams' => true, 'copy_songs' => true]);
    }

    public function test_creating_from_a_template_can_fill_several_weeks_without_people_or_songs(): void
    {
        $church = $this->signInPlanner();
        $source = Event::factory()->create(['church_id' => $church->id, 'is_template' => true, 'starts_at' => '2026-01-04 10:00:00']);
        $person = User::factory()->create(['church_id' => $church->id]);
        $activity = TaskTemplate::factory()->create(['church_id' => $church->id, 'use_globally' => false]);
        EventTask::factory()->create(['event_id' => $source->id, 'task_template_id' => $activity->id, 'assigned_user_id' => $person->id, 'song' => 'Amazing Grace', 'duration' => 10, 'sort_order' => 1]);

        Livewire::test(CreateEvent::class)
            ->fillForm(['service_template_id' => $source->id])
            ->fillForm(['starts_at' => '2026-12-06', 'weeks' => 3, 'copy_people_and_teams' => false, 'copy_songs' => false])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__(':count services copied', ['count' => 3]));

        $events = Event::query()->orderBy('starts_at')->get();
        $this->assertSame(
            ['2026-12-06 10:00:00', '2026-12-13 10:00:00', '2026-12-20 10:00:00'],
            $events->map(fn (Event $event): string => $event->starts_at->toDateTimeString())->all(),
        );

        foreach ($events as $event) {
            $task = $event->tasks()->sole();
            $this->assertNull($task->assigned_user_id);
            $this->assertNull($task->song);
        }
    }

    public function test_clearing_the_template_keeps_the_details_and_creates_a_blank_event(): void
    {
        $church = $this->signInPlanner();
        $source = Event::factory()->create(['church_id' => $church->id, 'is_template' => true, 'name' => 'Sunday template', 'starts_at' => '2026-01-04 10:00:00']);
        $activity = TaskTemplate::factory()->create(['church_id' => $church->id]);
        EventTask::factory()->create(['event_id' => $source->id, 'task_template_id' => $activity->id]);

        Livewire::test(CreateEvent::class)
            ->fillForm(['service_template_id' => $source->id])
            ->fillForm(['service_template_id' => null])
            ->assertFormSet(['name' => 'Sunday template', 'start_time' => '10:00'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, Event::query()->sole()->tasks()->count());
    }

    #[TestWith(['another_church'])]
    #[TestWith(['ordinary_event'])]
    #[TestWith(['deleted_template'])]
    public function test_invalid_template_selections_do_not_create_an_event(string $kind): void
    {
        $church = $this->signInPlanner();
        $source = Event::factory()->create([
            'church_id' => $kind === 'another_church' ? Church::factory()->create()->id : $church->id,
            'is_template' => $kind !== 'ordinary_event',
        ]);

        if ($kind === 'deleted_template') {
            $source->delete();
        }

        $eventsBefore = Event::query()->count();

        Livewire::test(CreateEvent::class)
            ->fillForm(['name' => 'New service', 'service_template_id' => $source->id])
            ->call('create')
            ->assertHasFormErrors(['service_template_id']);

        $this->assertSame($eventsBefore, Event::query()->count());
    }

    public function test_a_planner_without_template_permission_cannot_copy_a_template_through_the_form(): void
    {
        $church = $this->signInPlanner();
        Role::findByName('Event Planner', 'web')->revokePermissionTo('service_templates.use');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $source = Event::factory()->create(['church_id' => $church->id, 'is_template' => true]);
        $activity = TaskTemplate::factory()->create(['church_id' => $church->id]);
        EventTask::factory()->create(['event_id' => $source->id, 'task_template_id' => $activity->id]);

        Livewire::test(CreateEvent::class)
            ->assertFormFieldHidden('service_template_id')
            ->fillForm(['name' => 'Blank service', 'service_template_id' => $source->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Blank service', Event::query()->sole()->name);
        $this->assertSame(0, Event::query()->sole()->tasks()->count());
    }

    public function test_an_event_requires_both_a_date_and_a_time(): void
    {
        $this->signInPlanner();

        Livewire::test(CreateEvent::class)
            ->fillForm(['name' => 'Incomplete service', 'starts_at' => null, 'start_time' => null])
            ->call('create')
            ->assertHasFormErrors(['starts_at' => 'required', 'start_time' => 'required']);

        $this->assertSame(0, Event::query()->count());
    }

    #[TestWith(['25:00'])]
    #[TestWith(['invalid'])]
    public function test_invalid_start_times_are_rejected(string $time): void
    {
        $this->signInPlanner();

        Livewire::test(CreateEvent::class)
            ->fillForm(['name' => 'Invalid time', 'start_time' => $time])
            ->call('create')
            ->assertHasFormErrors(['start_time' => 'date_format']);

        $this->assertSame(0, Event::query()->count());
    }

    private function signInPlanner(): Church
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);
        $user = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, 'Event Planner');
        $this->actingAs($user);

        return $church;
    }
}
