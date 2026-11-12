<?php

namespace Tests\Feature\Messaging;

use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Enums\MessageTemplatePurpose;
use App\Filament\Pages\ChurchSettings;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\OrderTasksRelationManager;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\AssignmentResponder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Messaging\Mail\GenericMessageMail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeclineMessagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected Church $church;

    protected Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->withEmailSettings()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
        $this->event = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Sunday Service',
            'starts_at' => now()->addDays(4),
        ]);
    }

    public function test_declining_sends_the_chosen_template_to_the_person_on_the_decline_messages_task(): void
    {
        Mail::fake();
        $this->chooseTemplate('{{declined_by}} declined {{task}} on {{event}}: {{task_url}}');
        $coordinator = $this->member('Clara Coordinator', 'Church Admin');
        $this->task($coordinator, 'Service coordinator', receivesDeclineMessages: true);
        $bystander = $this->member('Ben Bystander');
        $this->task($bystander, 'Projection');
        $decliner = $this->member('Dan Decliner');
        $declined = $this->task($decliner, 'Sound');

        app(AssignmentResponder::class)->decline($declined, $decliner);

        $logged = OutboundMessage::query()->where('meta->kind', 'decline_notice')->sole();
        $this->assertSame($coordinator->id, $logged->user_id);
        $taskUrl = EditEvent::getUrl(['record' => $this->event, 'relation' => '0', 'task' => $declined->id], panel: 'admin');
        $this->assertSame(e("Dan Decliner declined Sound on Sunday Service: {$taskUrl}"), $logged->body);
        Mail::assertSent(GenericMessageMail::class, fn (GenericMessageMail $mail): bool => $mail->hasTo($coordinator->email));
        Mail::assertNotSent(GenericMessageMail::class, fn (GenericMessageMail $mail): bool => $mail->hasTo($bystander->email));
    }

    public function test_the_link_opens_the_read_only_service_page_for_people_who_cannot_edit_it(): void
    {
        Mail::fake();
        $this->chooseTemplate('{{task_url}}');
        $member = $this->member('Mia Member');
        $this->task($member, 'Service host', receivesDeclineMessages: true);
        $decliner = $this->member('Dan Decliner');
        $declined = $this->task($decliner, 'Sound');

        app(AssignmentResponder::class)->decline($declined, $decliner);

        $this->assertSame(
            e(ViewEvent::getUrl(['record' => $this->event, 'relation' => '0', 'task' => $declined->id], panel: 'admin')),
            OutboundMessage::query()->where('meta->kind', 'decline_notice')->sole()->body,
        );
    }

    public function test_no_decline_message_is_sent_without_a_template_in_the_church_settings(): void
    {
        Mail::fake();
        $coordinator = $this->member('Clara Coordinator');
        $this->task($coordinator, 'Service coordinator', receivesDeclineMessages: true);
        $decliner = $this->member('Dan Decliner');
        $declined = $this->task($decliner, 'Sound');

        app(AssignmentResponder::class)->decline($declined, $decliner);

        Mail::assertNothingSent();
    }

    public function test_declining_the_decline_messages_task_itself_does_not_message_the_decliner(): void
    {
        Mail::fake();
        $this->chooseTemplate('{{declined_by}} declined {{task}}');
        $coordinator = $this->member('Clara Coordinator');
        $coordinatorTask = $this->task($coordinator, 'Service coordinator', receivesDeclineMessages: true);

        app(AssignmentResponder::class)->decline($coordinatorTask, $coordinator);

        Mail::assertNothingSent();
    }

    public function test_declining_again_does_not_send_the_message_twice(): void
    {
        Mail::fake();
        $this->chooseTemplate('{{declined_by}} declined {{task}}');
        $coordinator = $this->member('Clara Coordinator');
        $this->task($coordinator, 'Service coordinator', receivesDeclineMessages: true);
        $decliner = $this->member('Dan Decliner');
        $declined = $this->task($decliner, 'Sound');

        app(AssignmentResponder::class)->decline($declined, $decliner);
        app(AssignmentResponder::class)->decline($declined->fresh(), $decliner);

        $this->assertSame(1, OutboundMessage::query()->where('meta->kind', 'decline_notice')->count());
    }

    public function test_a_service_page_link_with_a_task_opens_that_task_for_editing(): void
    {
        $admin = $this->member('Clara Coordinator', 'Church Admin');
        $task = $this->task($this->member('Dan Decliner'), 'Sound');
        $this->actingAs($admin);

        Livewire::withQueryParams(['task' => (string) $task->id])
            ->test(OrderTasksRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class])
            ->assertActionMounted(TestAction::make('edit')->table($task));
    }

    public function test_a_read_only_service_page_link_with_a_task_opens_that_task_to_view(): void
    {
        $member = $this->member('Mia Member');
        $task = $this->task($this->member('Dan Decliner'), 'Sound');
        $this->actingAs($member);

        Livewire::withQueryParams(['task' => (string) $task->id])
            ->test(OrderTasksRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => ViewEvent::class])
            ->assertActionMounted(TestAction::make('view')->table($task));
    }

    public function test_church_settings_save_the_decline_message_template(): void
    {
        $template = $this->template('{{declined_by}} declined {{task}}');
        $this->actingAs($this->member('Clara Coordinator', 'Church Admin'));

        Livewire::test(ChurchSettings::class)
            ->fillForm(['decline_message_template_id' => $template->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($template->id, $this->church->refresh()->decline_message_template_id);
    }

    protected function chooseTemplate(string $text): MessageTemplate
    {
        $template = $this->template($text);
        $this->church->update(['decline_message_template_id' => $template->id]);

        return $template;
    }

    protected function template(string $text): MessageTemplate
    {
        return MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'name' => 'Decline notice',
            'purpose' => MessageTemplatePurpose::Other,
            'subject' => 'Declined',
            'body' => '',
            'text_body' => $text,
            'is_active' => true,
        ]);
    }

    protected function member(string $name, string $role = 'Member'): User
    {
        $user = User::factory()->create(['church_id' => $this->church->id, 'name' => $name, 'display_name' => $name]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, $role);

        return $user;
    }

    protected function task(User $person, string $name, bool $receivesDeclineMessages = false): EventTask
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'name' => $name,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
            'receives_decline_messages' => $receivesDeclineMessages,
        ]);

        return EventTask::factory()->create([
            'event_id' => $this->event->id,
            'task_template_id' => $template->id,
            'name' => $name,
            'assigned_user_id' => $person->id,
        ]);
    }
}
