<?php

namespace Tests\Feature\Events;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentResponseStatus;
use App\Enums\FieldUsage;
use App\Filament\Resources\Events\Pages\TaskSpreadsheet;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\OrderTasksRelationManager;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SelfAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $volunteer;

    protected TaskTemplate $template;

    protected Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);

        $this->volunteer = User::factory()->create(['church_id' => $this->church->id, 'display_name' => 'Vera']);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->volunteer, 'Member');
        $this->volunteer->givePermissionTo('events.assign_self');

        $this->template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Reader',
            'use_globally' => false,
            'person_usage' => FieldUsage::Optional,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $this->event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addDays(3)->setTime(10, 0),
        ]);
    }

    public function test_open_and_own_tasks_can_be_self_assigned(): void
    {
        $open = $this->task();
        $own = $this->task(['assigned_user_id' => $this->volunteer->id]);

        $this->assertTrue($this->volunteer->can('selfAssign', $open));
        $this->assertTrue($this->volunteer->can('selfAssign', $own));
    }

    public function test_tasks_taken_by_someone_else_or_a_guest_cannot_be_self_assigned(): void
    {
        $other = User::factory()->create(['church_id' => $this->church->id]);

        $this->assertFalse($this->volunteer->can('selfAssign', $this->task(['assigned_user_id' => $other->id])));
        $this->assertFalse($this->volunteer->can('selfAssign', $this->task(['guest_person' => true, 'person_text' => 'Visiting pastor'])));
    }

    public function test_self_assignment_needs_the_permission_and_gives_planners_nothing_extra(): void
    {
        $task = $this->task();
        $this->volunteer->revokePermissionTo('events.assign_self');
        $this->assertFalse($this->volunteer->fresh()->can('selfAssign', $task));

        $planner = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($planner, 'Event Planner');
        $planner->givePermissionTo('events.assign_self');
        $this->assertFalse($planner->can('selfAssign', $task));
    }

    public function test_tasks_without_a_person_field_templates_and_other_churches_cannot_be_self_assigned(): void
    {
        $teamOnly = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::NotUsed,
            'team_usage' => FieldUsage::Optional,
        ]);
        $serviceTemplate = Event::factory()->create(['church_id' => $this->church->id, 'is_template' => true]);
        $otherChurchEvent = Event::factory()->create(['church_id' => Church::factory()->create()->id]);

        $this->assertFalse($this->volunteer->can('selfAssign', $this->task(['task_template_id' => $teamOnly->id])));
        $this->assertFalse($this->volunteer->can('selfAssign', $this->task(['event_id' => $serviceTemplate->id])));
        $this->assertFalse($this->volunteer->can('selfAssign', $this->task(['event_id' => $otherChurchEvent->id])));
    }

    public function test_abilities_limit_which_open_tasks_can_be_taken(): void
    {
        app(ChurchModuleManager::class)->enable($this->church, 'abilities');
        $task = $this->task();

        $this->assertFalse($this->volunteer->can('selfAssign', $task));

        $this->volunteer->abilityTemplates()->attach($this->template);
        $this->assertTrue($this->volunteer->fresh()->can('selfAssign', $task->fresh()));
    }

    public function test_service_page_offers_add_me_only_on_open_tasks(): void
    {
        $open = $this->task();
        $taken = $this->task(['assigned_user_id' => User::factory()->create(['church_id' => $this->church->id])->id]);
        $this->actingAs($this->volunteer);

        $this->serviceOrder()
            ->assertTableActionVisible('selfAssign', $open)
            ->assertTableActionHidden('selfAssign', $taken)
            ->assertTableActionHidden('edit', $open)
            ->assertTableActionHidden('delete', $open)
            ->assertTableActionHidden('moveUp', $open)
            ->assertTableActionHidden('create');
    }

    public function test_adding_yourself_assigns_you_as_confirmed_and_ignores_every_other_field(): void
    {
        $task = $this->task(['sort_order' => 4, 'duration' => 5, 'before_start' => false]);
        $otherTemplate = TaskTemplate::factory()->create(['church_id' => $this->church->id]);
        $team = Team::factory()->create(['church_id' => $this->church->id]);
        $this->actingAs($this->volunteer);

        $this->serviceOrder()
            ->callTableAction('selfAssign', $task, data: [
                'task_template_id' => $otherTemplate->id,
                'assigned_team_id' => $team->id,
                'sort_order' => 99,
                'before_start' => true,
                'accepted_verbally' => true,
                'duration' => 12,
            ])
            ->assertHasNoTableActionErrors();

        $task->refresh();
        $this->assertSame($this->volunteer->id, $task->assigned_user_id);
        $this->assertSame($this->template->id, $task->task_template_id);
        $this->assertNull($task->assigned_team_id);
        $this->assertSame(4, $task->sort_order);
        $this->assertFalse($task->before_start);
        $this->assertNull($task->accepted_verbally_at);
        $this->assertSame(12, $task->duration);
        $this->assertSame(AssignmentResponseStatus::Confirmed, $task->responses()->latest('id')->first()?->status);
    }

    public function test_choosing_someone_else_is_refused(): void
    {
        $task = $this->task();
        $other = User::factory()->create(['church_id' => $this->church->id]);
        $this->actingAs($this->volunteer);

        $this->serviceOrder()
            ->callTableAction('selfAssign', $task, data: ['assigned_user_id' => $other->id])
            ->assertHasTableActionErrors(['assigned_user_id']);

        $this->assertNull($task->refresh()->assigned_user_id);
    }

    public function test_you_can_change_your_own_task_but_not_remove_yourself(): void
    {
        $task = $this->task(['assigned_user_id' => $this->volunteer->id, 'duration' => 5]);
        $this->actingAs($this->volunteer);

        $this->serviceOrder()
            ->assertTableActionVisible('selfAssign', $task)
            ->callTableAction('selfAssign', $task, data: [
                'assigned_user_id' => null,
                'duration' => 8,
                'special_needs' => 'Needs a music stand',
            ])
            ->assertHasNoTableActionErrors();

        $task->refresh();
        $this->assertSame($this->volunteer->id, $task->assigned_user_id);
        $this->assertSame(8, $task->duration);
    }

    public function test_spreadsheet_lets_you_add_yourself(): void
    {
        $this->volunteer->givePermissionTo('events.tasks_by_event');
        $task = $this->task();
        $this->actingAs($this->volunteer);

        Livewire::test(TaskSpreadsheet::class)
            ->callAction('editEventTask', arguments: ['taskId' => $task->id])
            ->assertHasNoActionErrors();

        $this->assertSame($this->volunteer->id, $task->refresh()->assigned_user_id);
    }

    public function test_spreadsheet_does_not_save_tasks_for_people_who_cannot_edit_them(): void
    {
        $this->volunteer->givePermissionTo('events.tasks_by_event');
        $someoneElse = User::factory()->create(['church_id' => $this->church->id]);
        $task = $this->task(['assigned_user_id' => $someoneElse->id, 'duration' => 5]);
        $this->actingAs($this->volunteer);

        Livewire::test(TaskSpreadsheet::class)
            ->callAction('editEventTask', data: ['assigned_user_id' => $this->volunteer->id, 'duration' => 30], arguments: ['taskId' => $task->id]);

        $task->refresh();
        $this->assertSame($someoneElse->id, $task->assigned_user_id);
        $this->assertSame(5, $task->duration);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function task(array $attributes = []): EventTask
    {
        return EventTask::factory()->create([
            'event_id' => $this->event->id,
            'task_template_id' => $this->template->id,
            ...$attributes,
        ]);
    }

    protected function serviceOrder(): mixed
    {
        return Livewire::test(OrderTasksRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => ViewEvent::class]);
    }
}
