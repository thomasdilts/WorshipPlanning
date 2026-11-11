<?php

namespace Tests\Feature\Events;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentState;
use App\Enums\FieldUsage;
use App\Enums\MessageTemplatePurpose;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\TaskSpreadsheet;
use App\Filament\Resources\Events\RelationManagers\TasksRelationManager;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventMessage;
use App\Models\EventTask;
use App\Models\MessageTemplate;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\AssignmentResponder;
use App\Services\AssignmentStateResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaskResponseStatusTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $admin;

    protected User $person;

    protected Event $event;

    protected EventTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        $this->person = User::factory()->create(['church_id' => $this->church->id, 'display_name' => 'Anna']);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->person, 'Member');

        $this->event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addDays(5),
        ]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'use_globally' => true,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);

        $this->task = EventTask::factory()->create([
            'event_id' => $this->event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $this->person->id,
        ]);

        $this->actingAs($this->admin);
    }

    public function test_accepted_verbally_overrides_a_later_decline_by_message(): void
    {
        $this->task->update(['accepted_verbally_at' => now(), 'accepted_verbally_by_user_id' => $this->admin->id]);

        app(AssignmentResponder::class)->decline($this->task->fresh(), $this->person);

        $this->assertSame(AssignmentState::ConfirmedVerbally, app(AssignmentStateResolver::class)->for($this->task->fresh()));
    }

    public function test_spreadsheet_labels_an_assigned_person_who_has_not_been_invited(): void
    {
        Livewire::test(TaskSpreadsheet::class)
            ->assertSuccessful()
            ->assertSee('Anna')
            ->assertSee('Assigned, not invited')
            ->assertDontSee('Accepted verbally');

        $this->task->update(['accepted_verbally_at' => now()]);

        Livewire::test(TaskSpreadsheet::class)
            ->assertSuccessful()
            ->assertSee('Accepted verbally')
            ->assertDontSee('Assigned, not invited');
    }

    public function test_reassigning_the_task_clears_the_verbal_acceptance(): void
    {
        $this->task->update(['accepted_verbally_at' => now(), 'accepted_verbally_by_user_id' => $this->admin->id]);

        $this->task->update(['assigned_user_id' => User::factory()->create(['church_id' => $this->church->id])->id]);

        $this->assertNull($this->task->fresh()->accepted_verbally_at);
        $this->assertSame(AssignmentState::Draft, app(AssignmentStateResolver::class)->for($this->task->fresh()));
    }

    public function test_task_edit_sets_and_clears_accepted_verbally(): void
    {
        $this->tasksTable()
            ->callTableAction('edit', $this->task, data: ['accepted_verbally' => true])
            ->assertHasNoTableActionErrors();

        $this->assertNotNull($this->task->fresh()->accepted_verbally_at);
        $this->assertSame($this->admin->id, $this->task->fresh()->accepted_verbally_by_user_id);

        $this->tasksTable()
            ->callTableAction('edit', $this->task, data: ['accepted_verbally' => false])
            ->assertHasNoTableActionErrors();

        $this->assertNull($this->task->fresh()->accepted_verbally_at);
    }

    public function test_status_is_shown_beside_the_person_and_old_row_action_is_gone(): void
    {
        app(AssignmentResponder::class)->decline($this->task, $this->person);

        $this->tasksTable()
            ->assertSee(AssignmentState::Declined->label())
            ->assertTableActionDoesNotExist('acceptedVerbally');

        $this->task->update(['accepted_verbally_at' => now()]);

        $this->tasksTable()->assertSee(AssignmentState::ConfirmedVerbally->label());
    }

    public function test_save_and_send_message_opens_the_message_popup_with_the_person_selected(): void
    {
        app(ChurchModuleManager::class)->enable($this->church, 'messaging');
        $responseTemplate = MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'key' => 'krava-svar',
            'name' => 'Our renamed invitation',
            'purpose' => MessageTemplatePurpose::Service,
            'is_active' => true,
            'body' => '<p>Invitation preview</p>',
            'text_body' => 'Separate text preview',
        ]);

        $this->tasksTable()
            ->callAction(TestAction::make('edit')->table($this->task)->arguments(['sendMessage' => true]))
            ->assertHasNoErrors()
            ->assertActionMounted('sendTaskMessage')
            ->assertSchemaStateSet([
                'recipient_task_ids' => [$this->task->id],
                'message_template_id' => $responseTemplate->id,
            ])
            ->assertMountedActionModalSee('Invitation preview')
            ->assertMountedActionModalDontSee(['Separate text preview', 'Text message (SMS / WhatsApp)'])
            ->fillForm(['message_template_id' => MessageTemplate::query()->create([
                'church_id' => $this->church->id,
                'name' => 'Ask',
                'subject' => 'Can you come?',
                'text_body' => 'Hi',
            ])->id])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $message = EventMessage::query()->where('event_id', $this->event->id)->sole();

        $this->assertSame('Can you come?', $message->subject);
        $this->assertSame([$this->person->id], $message->recipients()->pluck('users.id')->all());
    }

    public function test_spreadsheet_save_and_send_preselects_the_response_template(): void
    {
        app(ChurchModuleManager::class)->enable($this->church, 'messaging');
        $responseTemplate = MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'key' => 'krava-svar',
            'name' => 'Require response',
            'text_body' => 'Please reply',
            'purpose' => MessageTemplatePurpose::Service,
            'is_active' => true,
        ]);

        Livewire::test(TaskSpreadsheet::class)
            ->callAction('editEventTask', arguments: ['taskId' => $this->task->id, 'sendMessage' => true])
            ->assertHasNoErrors()
            ->assertActionMounted('sendTaskMessage')
            ->assertSchemaStateSet([
                'recipient_task_ids' => [$this->task->id],
                'message_template_id' => $responseTemplate->id,
            ]);
    }

    public function test_save_and_send_does_not_preselect_an_inactive_response_template(): void
    {
        app(ChurchModuleManager::class)->enable($this->church, 'messaging');
        MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'key' => 'krava-svar',
            'name' => 'Require response',
            'text_body' => 'Please reply',
            'purpose' => MessageTemplatePurpose::Service,
            'is_active' => false,
        ]);

        $this->tasksTable()
            ->callAction(TestAction::make('edit')->table($this->task)->arguments(['sendMessage' => true]))
            ->assertActionMounted('sendTaskMessage')
            ->assertSchemaStateSet(['message_template_id' => null]);
    }

    public function test_creating_a_task_can_save_and_open_the_message_popup_with_the_person_selected(): void
    {
        app(ChurchModuleManager::class)->enable($this->church, 'messaging');

        $table = $this->tasksTable()
            ->assertActionExists(TestAction::make('create')->table(), fn ($action): bool => ! $action->canCreateAnother())
            ->callAction(TestAction::make('create')->table()->arguments(['sendMessage' => true]), data: [
                'task_template_id' => $this->task->task_template_id,
                'assigned_user_id' => $this->person->id,
            ])
            ->assertHasNoErrors()
            ->assertActionMounted('sendTaskMessage');

        $newTask = $this->event->tasks()->whereKeyNot($this->task->id)->sole();

        $table->assertSchemaStateSet(['recipient_task_ids' => [$newTask->id]]);
        $this->assertSame($this->person->id, $newTask->assigned_user_id);
    }

    public function test_save_and_send_message_without_anyone_assigned_only_warns(): void
    {
        app(ChurchModuleManager::class)->enable($this->church, 'messaging');

        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'use_globally' => true,
            'person_usage' => FieldUsage::Optional,
            'team_usage' => FieldUsage::NotUsed,
        ]);

        $this->tasksTable()
            ->callAction(TestAction::make('create')->table()->arguments(['sendMessage' => true]), data: [
                'task_template_id' => $template->id,
            ])
            ->assertHasNoErrors()
            ->assertActionNotMounted('sendTaskMessage')
            ->assertNotified(__('No person or team on this task to message'));
    }

    protected function tasksTable(): mixed
    {
        return Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $this->event,
            'pageClass' => EditEvent::class,
        ]);
    }
}
