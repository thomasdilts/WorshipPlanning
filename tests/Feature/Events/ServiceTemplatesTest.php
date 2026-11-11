<?php

namespace Tests\Feature\Events;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ListServiceTemplates;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventMessage;
use App\Models\EventTask;
use App\Models\Song;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ServiceTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $person;

    protected Team $team;

    protected TaskTemplate $taskTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 08:00:00');
        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);

        $this->person = User::factory()->create(['church_id' => $this->church->id]);
        $this->team = Team::factory()->create(['church_id' => $this->church->id]);
        $this->taskTemplate = TaskTemplate::factory()->create(['church_id' => $this->church->id, 'use_globally' => false]);
    }

    public function test_church_admin_saves_a_service_as_a_template_with_people_teams_and_songs_but_no_messages(): void
    {
        $service = $this->serviceWithTask(['song' => 'Amazing Grace']);
        EventMessage::query()->create(['church_id' => $this->church->id, 'event_id' => $service->id, 'subject' => 'Rehearsal', 'status' => 'sent']);
        $this->actingAs($this->userWithRole('Church Admin'));

        Livewire::test(ListEvents::class)
            ->callTableAction('saveAsTemplate', $service, data: ['name' => 'Sunday template'])
            ->assertHasNoTableActionErrors();

        $template = Event::templates()->sole();
        $task = $template->tasks()->sole();

        $this->assertSame('Sunday template', $template->name);
        $this->assertSame($this->person->id, $task->assigned_user_id);
        $this->assertSame($this->team->id, $task->assigned_team_id);
        $this->assertSame('Amazing Grace', $task->song);
        $this->assertSame(0, $template->messages()->count());
        $this->assertSame([$service->id], Event::query()->pluck('id')->all());
    }

    public function test_event_planners_cannot_save_a_service_as_a_template(): void
    {
        $service = $this->serviceWithTask();
        $this->actingAs($this->userWithRole('Event Planner'));

        Livewire::test(ListEvents::class)
            ->assertTableActionHidden('saveAsTemplate', $service);
    }

    public function test_church_admin_adds_a_template_from_an_old_service_on_the_templates_page(): void
    {
        $source = $this->serviceWithTask(['song' => 'Amazing Grace']);
        $source->update(['starts_at' => '2025-01-05 11:00:00']);
        $this->actingAs($this->userWithRole('Church Admin'));

        Livewire::test(ListServiceTemplates::class)
            ->callAction('createTemplate', data: ['source_event_id' => $source->id, 'name' => 'Sunday worship'])
            ->assertHasNoActionErrors();

        $template = Event::templates()->sole();
        $this->assertSame('Sunday worship', $template->name);
        $this->assertSame('2025-01-05 11:00:00', $template->starts_at->toDateTimeString());
        $this->assertSame($this->person->id, $template->tasks()->sole()->assigned_user_id);
        $this->assertSame($this->team->id, $template->tasks()->sole()->assigned_team_id);
        $this->assertSame('Amazing Grace', $template->tasks()->sole()->song);
        $this->assertModelExists($source);
    }

    public function test_event_planners_do_not_see_the_add_template_button(): void
    {
        $this->actingAs($this->userWithRole('Event Planner'));

        Livewire::test(ListServiceTemplates::class)->assertActionHidden('createTemplate');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_the_add_template_action_rejects_other_churches_and_existing_templates(bool $isTemplate): void
    {
        $source = Event::factory()->create([
            'church_id' => $isTemplate ? $this->church->id : Church::factory()->create()->id,
            'is_template' => $isTemplate,
        ]);
        $this->actingAs($this->userWithRole('Church Admin'));
        $templatesBefore = Event::templates()->count();

        Livewire::test(ListServiceTemplates::class)
            ->callAction('createTemplate', data: ['source_event_id' => $source->id, 'name' => 'Invalid source'])
            ->assertHasActionErrors(['source_event_id']);

        $this->assertSame($templatesBefore, Event::templates()->count());
    }

    public function test_event_planner_creates_a_service_from_a_template_with_its_people(): void
    {
        $template = $this->serviceWithTask(['is_template' => true]);
        $this->actingAs($this->userWithRole('Event Planner'));

        Livewire::test(ListServiceTemplates::class)
            ->assertCanSeeTableRecords([$template])
            ->assertTableActionHidden('delete', $template)
            ->callTableAction('copy', $template, data: [
                'name' => 'Sunday service',
                'starts_at' => '2026-11-08 10:00:00',
                'weeks' => 1,
                'copy_people_and_teams' => true,
                'copy_songs' => true,
            ])
            ->assertHasNoTableActionErrors();

        $service = Event::query()->sole();

        $this->assertFalse($service->is_template);
        $this->assertSame($this->person->id, $service->tasks()->sole()->assigned_user_id);
        $this->assertSame('10:00:00', $service->tasks()->sole()->starts_at);
        $this->assertTrue(Event::templates()->whereKey($template->id)->exists());
    }

    public function test_members_cannot_open_service_templates(): void
    {
        $this->actingAs($this->userWithRole('Member'));

        $this->get(EventResource::getUrl('templates'))->assertForbidden();
    }

    public function test_only_church_admins_edit_a_template(): void
    {
        $template = $this->serviceWithTask(['is_template' => true]);

        $this->actingAs($this->userWithRole('Event Planner'));
        $this->get(EventResource::getUrl('edit', ['record' => $template]))->assertForbidden();
        $this->get(EventResource::getUrl('view', ['record' => $template]))->assertOk();

        $this->actingAs($this->userWithRole('Church Admin'));
        $this->get(EventResource::getUrl('edit', ['record' => $template]))->assertOk();
    }

    public function test_templates_and_their_tasks_stay_out_of_services_and_assignments(): void
    {
        $template = $this->serviceWithTask(['is_template' => true, 'song_id' => Song::factory()->create(['church_id' => $this->church->id])->id]);
        $this->actingAs($this->userWithRole('Church Admin'));

        Livewire::test(ListEvents::class)->assertCanNotSeeTableRecords([$template]);

        $this->assertSame(0, Event::query()->count());
        $this->assertSame(0, EventTask::query()->count());
        $this->assertSame(0, $this->person->assignedTasks()->count());
        $this->assertSame(0, Song::query()->withCount('eventTasks')->sole()->event_tasks_count);
        $this->assertSame($template->id, $template->tasks()->sole()->event?->id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function serviceWithTask(array $overrides = []): Event
    {
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => '2026-11-01 10:00:00',
            'is_template' => $overrides['is_template'] ?? false,
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $this->taskTemplate->id,
            'assigned_user_id' => $this->person->id,
            'assigned_team_id' => $this->team->id,
            'duration' => 10,
            'sort_order' => 1,
            'song' => $overrides['song'] ?? null,
            'song_id' => $overrides['song_id'] ?? null,
        ]);

        return $event;
    }

    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, $role);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);

        return $user;
    }
}
