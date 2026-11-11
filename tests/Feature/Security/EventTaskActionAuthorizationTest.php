<?php

namespace Tests\Feature\Security;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Filament\Resources\Events\Pages\TaskSpreadsheet;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\TasksRelationManager;
use App\Filament\Resources\Events\Schemas\EventMessageForm;
use App\Models\Event;
use App\Models\EventMessage;
use App\Models\EventTask;
use App\Models\MessageTemplate;
use App\Models\TaskTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TwoChurchFixture;
use Tests\TestCase;

/**
 * Task popups are Livewire actions the browser can open directly, so each must check permissions and
 * church itself, not rely on which buttons are shown.
 */
class EventTaskActionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private TwoChurchFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = TwoChurchFixture::create($this);
        app(ChurchModuleManager::class)->enable($this->fixture->churchA, 'messaging', [
            'email' => [
                'host' => 'smtp.example.org', 'port' => 587, 'username' => '', 'password' => '',
                'encryption' => 'tls', 'from_address' => 'church@example.org', 'from_name' => 'Church',
            ],
        ]);
    }

    public function test_member_without_messaging_send_cannot_send_a_task_message(): void
    {
        [$task, $messageTemplate] = $this->taskWithMessageTemplate();
        $this->actAsChurchA($this->fixture->memberA);

        Livewire::test(TasksRelationManager::class, ['ownerRecord' => $this->fixture->eventA, 'pageClass' => ViewEvent::class])
            ->assertActionHidden('sendTaskMessage', ['taskId' => $task->id]);

        try {
            EventMessageForm::create($this->fixture->eventA, ['message_template_id' => $messageTemplate->id, 'recipient_task_ids' => [$task->id]]);
            $this->fail('Sending without messaging.send must be refused.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(0, EventMessage::query()->count());
    }

    public function test_event_planner_sends_a_task_message(): void
    {
        [$task, $messageTemplate] = $this->taskWithMessageTemplate();
        $planner = User::factory()->create(['church_id' => $this->fixture->churchA->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($planner, 'Event Planner');
        $this->actAsChurchA($planner);

        Livewire::test(TasksRelationManager::class, ['ownerRecord' => $this->fixture->eventA, 'pageClass' => ViewEvent::class])
            ->mountAction('sendTaskMessage', ['taskId' => $task->id])
            ->fillForm(['message_template_id' => $messageTemplate->id, 'recipient_task_ids' => [$task->id]])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(1, EventMessage::query()->where('event_id', $this->fixture->eventA->id)->count());
    }

    public function test_choose_activity_does_not_list_another_churchs_task(): void
    {
        $assignee = User::factory()->create(['church_id' => $this->fixture->churchB->id, 'display_name' => 'Hidden Beta Person']);
        $foreignTask = $this->task($this->fixture->eventB, $assignee, 'Hidden Beta Activity');
        $this->actAsChurchA($this->fixture->adminA);

        Livewire::test(TaskSpreadsheet::class)
            ->mountAction('chooseEventTask', ['taskIds' => [$foreignTask->id]])
            ->assertMountedActionModalDontSee(['Hidden Beta Activity', 'Hidden Beta Person']);
    }

    /**
     * @return array{0: EventTask, 1: MessageTemplate}
     */
    private function taskWithMessageTemplate(): array
    {
        $assignee = User::factory()->create(['church_id' => $this->fixture->churchA->id]);
        $this->fixture->eventA->update(['starts_at' => now()->addDays(5)]);

        return [
            $this->task($this->fixture->eventA, $assignee, 'Greeter'),
            MessageTemplate::query()->create([
                'church_id' => $this->fixture->churchA->id, 'name' => 'Ask', 'subject' => 'Can you serve?',
                'body' => '<p>Can you serve?</p>', 'text_body' => 'Can you serve?',
            ]),
        ];
    }

    private function task(Event $event, User $assignee, string $name): EventTask
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $event->church_id, 'use_globally' => true,
            'person_usage' => FieldUsage::Required, 'team_usage' => FieldUsage::NotUsed,
        ]);

        return EventTask::factory()->create([
            'event_id' => $event->id, 'task_template_id' => $template->id,
            'assigned_user_id' => $assignee->id, 'name' => $name,
        ]);
    }

    private function actAsChurchA(User $user): void
    {
        app(CurrentChurch::class)->set($this->fixture->churchA);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->fixture->churchA->id);
        $this->actingAs($user);
    }
}
