<?php

namespace Tests\Feature\Events;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentState;
use App\Enums\FieldUsage;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\TasksRelationManager;
use App\Filament\Resources\Events\Schemas\EventTaskForm;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\AssignmentStateResolver;
use App\Services\EventStaffingSummary;
use App\Services\EventTaskRowSummary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GuestAssigneeTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $admin;

    protected Event $event;

    protected TaskTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($this->church, 'teams');

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        $this->event = Event::factory()->create(['church_id' => $this->church->id]);
        $this->template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::Optional,
            'description_usage' => FieldUsage::NotUsed,
            'special_needs_usage' => FieldUsage::NotUsed,
        ]);

        app(CurrentChurch::class)->set($this->church);
        $this->actingAs($this->admin);
    }

    public function test_a_named_guest_fills_a_required_person_and_team(): void
    {
        $this->createTask([
            'assigned_user_id' => EventTaskForm::GUEST,
            'person_text' => '  Visiting pastor Anna  ',
            'assigned_team_id' => EventTaskForm::GUEST,
            'team_text' => 'Visiting choir',
        ]);

        $task = EventTask::query()->sole();
        $this->assertTrue($task->guest_person);
        $this->assertNull($task->assigned_user_id);
        $this->assertSame('Visiting pastor Anna', $task->person_text);
        $this->assertTrue($task->guest_team);
        $this->assertSame('Visiting choir', $task->team_text);

        $summary = app(EventTaskRowSummary::class);
        $this->assertContains('Visiting pastor Anna', $summary->filledBits($task, $this->event));
        $this->assertContains(__('Team: ').'Visiting choir', $summary->filledBits($task, $this->event));
        $this->assertSame([], $summary->problems($task, $this->event));
        $this->assertSame(AssignmentState::Named, app(AssignmentStateResolver::class)->for($task));
        $this->assertSame(1, app(EventStaffingSummary::class)->for($this->event->fresh())['confirmed']);
        $this->assertSame(EventTaskForm::GUEST, EventTaskForm::fillFromRecord($task)['assigned_user_id']);
    }

    public function test_a_guest_without_a_name_is_flagged_as_incomplete(): void
    {
        $this->createTask(['assigned_user_id' => EventTaskForm::GUEST, 'person_text' => '   ']);

        $task = EventTask::query()->sole();
        $this->assertTrue($task->guest_person);
        $this->assertNull($task->person_text);
        $this->assertSame([__('Missing: Guest name')], app(EventTaskRowSummary::class)->problems($task, $this->event));
        $this->assertSame(1, app(EventStaffingSummary::class)->for($this->event->fresh())['vacant']);
    }

    public function test_editing_a_task_to_a_named_guest_keeps_the_guest(): void
    {
        $this->createTask([]);
        $task = EventTask::query()->sole();

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $this->event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('edit', $task, data: [
                'assigned_user_id' => EventTaskForm::GUEST,
                'person_text' => 'Visiting pastor Anna',
            ])
            ->assertHasNoTableActionErrors();

        $task->refresh();
        $this->assertTrue($task->guest_person);
        $this->assertNull($task->assigned_user_id);
        $this->assertSame('Visiting pastor Anna', $task->person_text);
    }

    public function test_picking_someone_from_the_list_replaces_the_guest(): void
    {
        $member = User::factory()->create(['church_id' => $this->church->id]);
        $this->createTask(['assigned_user_id' => EventTaskForm::GUEST, 'person_text' => 'Guest speaker']);
        $task = EventTask::query()->sole();

        EventTaskForm::persist($task, [
            ...EventTaskForm::fillFromRecord($task),
            'assigned_user_id' => $member->id,
        ], $this->event);

        $task->refresh();
        $this->assertSame($member->id, $task->assigned_user_id);
        $this->assertFalse($task->guest_person);
        $this->assertNull($task->person_text);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createTask(array $data): void
    {
        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $this->event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('create', data: [
                'task_template_id' => $this->template->id,
                'sort_order' => 1,
                ...$data,
            ])
            ->assertHasNoTableActionErrors();
    }
}
