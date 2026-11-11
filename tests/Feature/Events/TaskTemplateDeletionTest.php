<?php

namespace Tests\Feature\Events;

use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentResponseStatus;
use App\Filament\Resources\TaskTemplates\Pages\EditTaskTemplate;
use App\Filament\Resources\TaskTemplates\Pages\ListTaskTemplates;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\TaskTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskTemplateDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        app(CurrentChurch::class)->set($this->church);
        $this->actingAs($this->admin);
    }

    public function test_deleting_a_used_template_warns_and_removes_its_tasks_from_every_service(): void
    {
        $template = TaskTemplate::factory()->create(['church_id' => $this->church->id]);
        $otherTemplate = TaskTemplate::factory()->create(['church_id' => $this->church->id]);
        $service = Event::factory()->create(['church_id' => $this->church->id]);
        $serviceTemplate = Event::factory()->create(['church_id' => $this->church->id, 'is_template' => true]);
        $answeredTask = $this->taskOn($service, $template);
        $this->taskOn($serviceTemplate, $template);
        $keptTask = $this->taskOn($service, $otherTemplate);
        EventTaskResponse::factory()->create([
            'church_id' => $this->church->id,
            'event_task_id' => $answeredTask->id,
            'assignee_user_id' => $answeredTask->assigned_user_id,
            'status' => AssignmentResponseStatus::Confirmed,
        ]);

        Livewire::test(EditTaskTemplate::class, ['record' => $template->getRouteKey()])
            ->mountAction(DeleteAction::class)
            ->assertMountedActionModalSee(__('This task is used in :count services and service templates. Deleting it also removes it, with its assignments and answers, from all of them — past services included. This cannot be undone.', ['count' => 2]))
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertModelMissing($template);
        $this->assertSame(
            [$keptTask->id],
            EventTask::query()->withoutGlobalScope(EventTask::SERVICES_SCOPE)->pluck('id')->all(),
        );
        $this->assertSame(0, EventTaskResponse::query()->count());
    }

    public function test_bulk_delete_removes_the_selected_templates_tasks_from_services(): void
    {
        $template = TaskTemplate::factory()->create(['church_id' => $this->church->id]);
        $this->taskOn(Event::factory()->create(['church_id' => $this->church->id]), $template);

        Livewire::test(ListTaskTemplates::class)
            ->callTableBulkAction('delete', [$template]);

        $this->assertModelMissing($template);
        $this->assertSame(0, EventTask::query()->withoutGlobalScope(EventTask::SERVICES_SCOPE)->count());
    }

    protected function taskOn(Event $event, TaskTemplate $template): EventTask
    {
        return EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => User::factory()->create(['church_id' => $this->church->id])->id,
        ]);
    }
}
