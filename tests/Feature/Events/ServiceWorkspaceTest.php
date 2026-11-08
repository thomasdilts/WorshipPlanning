<?php

namespace Tests\Feature\Events;

use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\OrderTasksRelationManager;
use App\Filament\Resources\Events\RelationManagers\PeopleTasksRelationManager;
use App\Filament\Resources\Events\Schemas\EventTaskForm;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\EventStaffingSummary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ServiceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_shows_order_and_people_tabs_with_staffing_summary(): void
    {
        [$admin, $event] = $this->seedWorkspace();

        $this->actingAs($admin);

        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->assertSuccessful()
            ->assertDontSee('Tasks by event')
            ->assertSee('Print PDF')
            ->assertSee('More')
            ->assertSee('Order')
            ->assertSee('People')
            ->assertSee('not assigned')
            ->assertSee('Staffing');
    }

    public function test_return_query_does_not_restore_the_redundant_back_button(): void
    {
        [$admin, $event] = $this->seedWorkspace();

        $this->actingAs($admin);

        Livewire::test(EditEvent::class, [
            'record' => $event->getRouteKey(),
            'return' => 'tasks-by-event',
        ])
            ->assertSuccessful()
            ->assertDontSee('Tasks by event');
    }

    public function test_view_page_hides_reorder_controls_and_offers_edit(): void
    {
        [$admin, $event, $task] = $this->seedWorkspaceWithTasks();
        $this->actingAs($admin);

        Livewire::test(OrderTasksRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class])
            ->assertTableActionHidden('moveUp', $task)
            ->assertTableActionHidden('moveDown', $task)
            ->assertTableActionVisible('openWorkspace')
            ->assertSee('Edit')
            ->assertDontSee('Open workspace');
    }

    public function test_announcements_save_and_reopen_as_numbered_points(): void
    {
        [$admin, $event, $task] = $this->seedWorkspaceWithTasks();
        $task->template->update(['name' => 'Pålysningar', 'description_usage' => FieldUsage::Required]);
        $task->update(['name' => 'Pålysningar', 'description' => "• Church lunch\n• Youth meeting"]);
        $this->actingAs($admin);

        Livewire::test(OrderTasksRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->callTableAction('edit', $task, data: [
                'announcement_points' => [
                    ['text' => "Youth meeting\nBring a friend"],
                    ['text' => 'Church lunch'],
                    ['text' => 'Choir practice'],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame("1. Youth meeting\nBring a friend\n2. Church lunch\n3. Choir practice", $task->fresh()->description);
        $this->assertSame([
            ['text' => "Youth meeting\nBring a friend"],
            ['text' => 'Church lunch'],
            ['text' => 'Choir practice'],
        ], EventTaskForm::fillFromRecord($task->fresh())['announcement_points']);
    }

    public function test_required_announcements_can_be_cleared(): void
    {
        [$admin, $event, $task] = $this->seedWorkspaceWithTasks();
        $task->template->update(['name' => 'Pålysningar', 'description_usage' => FieldUsage::Required]);
        $task->update(['name' => 'Pålysningar', 'description' => 'Keep this announcement']);
        $this->actingAs($admin);

        Livewire::test(OrderTasksRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->callTableAction('edit', $task, data: ['announcement_points' => [['text' => '']]])
            ->assertHasNoTableActionErrors();

        $this->assertEmpty($task->fresh()->description);
    }

    public function test_optional_announcements_can_be_saved_without_points(): void
    {
        [$admin, $event, $task] = $this->seedWorkspaceWithTasks();
        $task->template->update(['name' => 'Pålysningar', 'description_usage' => FieldUsage::Optional]);
        $task->update(['name' => 'Pålysningar', 'description' => null]);
        $this->actingAs($admin);

        Livewire::test(OrderTasksRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->callTableAction('edit', $task, data: ['duration' => 8, 'announcement_points' => [['text' => '']]])
            ->assertHasNoTableActionErrors();

        $this->assertNull($task->fresh()->description);
        $this->assertSame(8, $task->fresh()->duration);
    }

    public function test_order_manager_lists_timed_tasks_and_people_lists_globals(): void
    {
        [$admin, $event, $timed, $global] = $this->seedWorkspaceWithTasks();

        $this->actingAs($admin);

        Livewire::test(OrderTasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->assertSuccessful()
            ->assertSee($timed->displayName())
            ->assertDontSee($global->displayName());

        Livewire::test(PeopleTasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->assertSuccessful()
            ->assertSee($global->displayName())
            ->assertDontSee($timed->displayName());
    }

    public function test_move_up_swaps_sort_order_and_recalculates_times(): void
    {
        [$admin, $event] = $this->seedWorkspace();
        $template = TaskTemplate::factory()->create([
            'church_id' => $admin->church_id,
            'use_globally' => false,
            'person_usage' => FieldUsage::NotUsed,
            'team_usage' => FieldUsage::NotUsed,
            'default_duration' => 10,
        ]);

        $first = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'name' => 'First song',
            'duration' => 10,
            'sort_order' => 1,
        ]);
        $second = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'name' => 'Second song',
            'duration' => 15,
            'sort_order' => 2,
        ]);

        $this->actingAs($admin);

        Livewire::test(OrderTasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('moveUp', $second)
            ->assertSuccessful();

        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertSame('10:00:00', $second->fresh()->starts_at);
    }

    public function test_event_end_is_calculated_from_timed_tasks_only(): void
    {
        [$admin, $event] = $this->seedWorkspace();
        $event->update([
            'starts_at' => '2026-11-01 10:00:00',
        ]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $admin->church_id,
            'use_globally' => false,
            'person_usage' => FieldUsage::NotUsed,
            'team_usage' => FieldUsage::NotUsed,
            'default_duration' => 30,
        ]);
        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'duration' => 30,
            'sort_order' => 1,
        ]);
        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => TaskTemplate::factory()->create(['church_id' => $admin->church_id, 'use_globally' => true])->id,
            'duration' => 90,
        ]);

        $summary = app(EventStaffingSummary::class)->for($event->fresh(['tasks.template']));

        $this->assertSame('10:30', $summary['calculated_ends_at']?->format('H:i'));
        $this->assertSame('2026-11-01 10:30', $event->fresh()->endsAt()->format('Y-m-d H:i'));
    }

    public function test_dragging_activities_reorders_and_recalculates_without_moving_whole_service_roles(): void
    {
        [$admin, $event, $first, $global] = $this->seedWorkspaceWithTasks();
        $second = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $first->task_template_id,
            'duration' => 10,
            'sort_order' => 2,
        ]);
        $this->actingAs($admin);

        Livewire::test(OrderTasksRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->call('reorderTable', [(string) $second->id, (string) $first->id])
            ->assertSuccessful();

        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame('10:00:00', $second->fresh()->starts_at);
        $this->assertSame('10:10:00', $first->fresh()->starts_at);
        $this->assertSame(0, $global->fresh()->sort_order);
    }

    public function test_dragging_rejects_a_task_from_another_service(): void
    {
        [$admin, $event, $first] = $this->seedWorkspaceWithTasks();
        $foreign = EventTask::factory()->create();
        $originalOrder = $foreign->sort_order;
        $this->actingAs($admin);

        Livewire::test(OrderTasksRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->call('reorderTable', [(string) $first->id, (string) $foreign->id])
            ->assertStatus(422);

        $this->assertSame($originalOrder, $foreign->fresh()->sort_order);
        $this->assertSame(1, $first->fresh()->sort_order);
    }

    public function test_read_only_users_cannot_reorder_activities(): void
    {
        [$admin, $event, $first] = $this->seedWorkspaceWithTasks();
        $member = User::factory()->create(['church_id' => $admin->church_id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($member, 'Member');
        $this->actingAs($member);

        Livewire::test(OrderTasksRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class])
            ->call('reorderTable', [(string) $first->id])
            ->assertSuccessful();

        $this->assertSame(1, $first->fresh()->sort_order);
    }

    public function test_toolbar_delete_event_targets_the_service_and_keeps_other_services(): void
    {
        [$admin, $event, $task] = $this->seedWorkspaceWithTasks();
        $other = Event::factory()->create(['church_id' => $admin->church_id]);
        $this->actingAs($admin);

        Livewire::test(OrderTasksRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->callTableAction('deleteEvent')
            ->assertHasNoTableActionErrors();

        $this->assertModelMissing($event);
        $this->assertModelMissing($task);
        $this->assertModelExists($other);
    }

    public function test_reordering_remains_available_after_editing_and_arrow_moves(): void
    {
        [$admin, $event, $first] = $this->seedWorkspaceWithTasks();
        $second = EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $first->task_template_id, 'sort_order' => 2, 'duration' => 10]);
        $this->actingAs($admin);

        Livewire::test(OrderTasksRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->call('reorderTable', [(string) $second->id, (string) $first->id])
            ->callTableAction('moveUp', $first)
            ->call('$refresh')
            ->call('reorderTable', [(string) $second->id, (string) $first->id])
            ->assertSuccessful();

        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame('10:00:00', $second->fresh()->starts_at);
        $this->assertSame('10:10:00', $first->fresh()->starts_at);
    }

    /**
     * @return array{0: User, 1: Event}
     */
    protected function seedWorkspace(): array
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        app(CurrentChurch::class)->set($church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sunday gathering',
            'starts_at' => '2026-11-01 10:00:00',
        ]);

        return [$admin, $event];
    }

    /**
     * @return array{0: User, 1: Event, 2: EventTask, 3: EventTask}
     */
    protected function seedWorkspaceWithTasks(): array
    {
        [$admin, $event] = $this->seedWorkspace();

        $timedTemplate = TaskTemplate::factory()->create([
            'church_id' => $admin->church_id,
            'name' => 'Song',
            'use_globally' => false,
            'person_usage' => FieldUsage::Optional,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $globalTemplate = TaskTemplate::factory()->create([
            'church_id' => $admin->church_id,
            'name' => 'Worship leader',
            'use_globally' => true,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);

        $timed = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $timedTemplate->id,
            'name' => 'Opening song',
            'sort_order' => 1,
            'duration' => 5,
        ]);
        $global = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $globalTemplate->id,
            'name' => 'Worship leader',
            'sort_order' => 0,
        ]);

        return [$admin, $event, $timed, $global];
    }
}
