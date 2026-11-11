<?php

namespace Tests\Feature\Messaging;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentResponseStatus;
use App\Enums\MessagePreference;
use App\Enums\MessageTemplatePurpose;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ManageEventMessages;
use App\Filament\Resources\Events\RelationManagers\OrderTasksRelationManager;
use App\Filament\Resources\Events\Schemas\EventMessageForm;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventMessage;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\User;
use App\Services\AssignmentResponder;
use App\Services\AssignmentResponseLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Messaging\Services\EventMessageService;
use Modules\Messaging\Support\MessagingAvailability;
use Tests\TestCase;

class EventMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $admin;

    protected Event $event;

    protected User $assignee;

    protected User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($this->church, 'messaging');

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        $this->assignee = User::factory()->create([
            'church_id' => $this->church->id,
            'display_name' => 'Assignee',
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->assignee, 'Member');

        $this->outsider = User::factory()->create([
            'church_id' => $this->church->id,
            'display_name' => 'Outsider',
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->outsider, 'Member');

        $this->event = Event::factory()->create(['church_id' => $this->church->id]);
        $template = TaskTemplate::factory()->create(['church_id' => $this->church->id]);

        EventTask::factory()->create([
            'event_id' => $this->event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $this->assignee->id,
        ]);

        app(CurrentChurch::class)->set($this->church);
    }

    public function test_event_edit_page_shows_messages_action_when_messaging_enabled(): void
    {
        $this->actingAs($this->admin);

        $component = Livewire::test(OrderTasksRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class]);

        $component->assertSuccessful()->assertSee('Service messages');

        $headerActions = $this->flattenHeaderActions($component->instance()->getTable()->getHeaderActions());

        $messagesAction = $headerActions->first(fn ($action) => $action->getName() === 'eventMessages');

        $this->assertNotNull($messagesAction);
        $this->assertTrue($messagesAction->isVisible());

        $this->assertNotNull(
            $headerActions->first(fn ($action) => $action->getName() === 'editDetails')
        );
    }

    public function test_messages_action_hidden_when_messaging_disabled(): void
    {
        app(ChurchModuleManager::class)->disable($this->church, 'messaging');
        $this->admin->unsetRelation('church');

        $this->actingAs($this->admin);

        $component = Livewire::test(OrderTasksRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class]);

        $component->assertSuccessful();

        $messagesAction = $this->flattenHeaderActions($component->instance()->getTable()->getHeaderActions())
            ->first(fn ($action) => $action->getName() === 'eventMessages');

        $this->assertNotNull($messagesAction);
        $this->assertFalse($messagesAction->isVisible());
    }

    /**
     * @param  array<int, mixed>  $actions
     * @return Collection<int, Action>
     */
    private function flattenHeaderActions(array $actions): Collection
    {
        return collect($actions)->flatMap(function ($action) {
            if ($action instanceof ActionGroup) {
                return $this->flattenHeaderActions($action->getActions());
            }

            return method_exists($action, 'getName') ? [$action] : [];
        });
    }

    public function test_can_open_event_messages_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/events/'.$this->event->id.'/messages')
            ->assertOk();
    }

    public function test_create_event_message_only_allows_event_participants(): void
    {
        $this->actingAs($this->admin);

        $message = app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: 'Please arrive early.',
            subject: 'Reminder',
            recipientUserIds: [$this->assignee->id, $this->outsider->id],
            sendNow: false,
        )->sole();

        $this->assertSame(EventMessage::STATUS_DRAFT, $message->status);
        $this->assertTrue($message->recipients->contains('id', $this->assignee->id));
        $this->assertFalse($message->recipients->contains('id', $this->outsider->id));
    }

    public function test_manage_page_lists_messages_for_the_event(): void
    {
        app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: 'Hello team',
            subject: 'Note',
            recipientUserIds: [$this->assignee->id],
            sendNow: false,
        );

        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, [
            'record' => $this->event->getRouteKey(),
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords(EventMessage::query()->where('event_id', $this->event->id)->get());
    }

    public function test_manage_page_shows_each_channel_a_message_was_sent_via_once(): void
    {
        $message = app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: 'Hello team',
            subject: 'Note',
            recipientUserIds: [$this->assignee->id],
            sendNow: false,
        )->sole();

        foreach (['sms', 'sms', 'whatsapp'] as $channel) {
            OutboundMessage::query()->create([
                'church_id' => $this->church->id,
                'event_id' => $this->event->id,
                'event_message_id' => $message->id,
                'user_id' => $this->assignee->id,
                'channel' => $channel,
                'body' => 'Hello team',
                'status' => 'sent',
            ]);
        }

        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, [
            'record' => $this->event->getRouteKey(),
        ])
            ->assertTableColumnStateSet('outboundMessages.channel', ['sms', 'whatsapp'], $message);
    }

    public function test_preview_shows_delivery_errors_for_failed_recipients(): void
    {
        $message = app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: 'Hello team',
            subject: 'Note',
            recipientUserIds: [$this->assignee->id],
            sendNow: true,
        )->sole();

        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, [
            'record' => $this->event->getRouteKey(),
        ])
            ->mountAction(TestAction::make('viewBody')->table($message))
            ->assertMountedActionModalSee([
                'Delivery errors',
                'Channel [email] is not configured.',
            ]);
    }

    public function test_resend_delivers_again_to_recipients_who_already_received_the_message(): void
    {
        Mail::fake();

        app(ChurchModuleManager::class)->enable($this->church, 'messaging', [
            'email' => [
                'host' => 'smtp.test',
                'port' => 587,
                'username' => '',
                'password' => '',
                'encryption' => 'tls',
                'from_address' => 'church@test',
                'from_name' => 'Church',
            ],
        ]);

        $message = app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: 'Hello team',
            subject: 'Note',
            recipientUserIds: [$this->assignee->id],
            sendNow: true,
        )->sole();

        $this->assertSame(EventMessage::STATUS_SENT, $message->status);

        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, [
            'record' => $this->event->getRouteKey(),
        ])
            ->callAction([
                TestAction::make('viewBody')->table($message),
                TestAction::make('resend'),
            ])
            ->assertNotified();

        Mail::assertSentCount(2);
        $this->assertSame(2, OutboundMessage::query()->where('event_message_id', $message->id)->count());
        $this->assertSame(EventMessage::STATUS_SENT, $message->fresh()->status);
    }

    public function test_resend_retries_failed_recipients(): void
    {
        $message = app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: 'Hello team',
            subject: 'Note',
            recipientUserIds: [$this->assignee->id],
            sendNow: true,
        )->sole();

        $this->assertSame(EventMessage::STATUS_FAILED, $message->status);

        Mail::fake();
        app(ChurchModuleManager::class)->updateSettings($this->church, 'messaging', [
            'email' => [
                'host' => 'smtp.test',
                'port' => 587,
                'username' => '',
                'password' => '',
                'encryption' => 'tls',
                'from_address' => 'church@test',
                'from_name' => 'Church',
            ],
        ]);

        $resent = app(EventMessageService::class)->resend($message);

        $this->assertSame(EventMessage::STATUS_SENT, $resent->status);
        $this->assertSame('sent', $resent->recipients->first()->pivot->delivery_status);
        $this->assertNull($resent->recipients->first()->pivot->delivery_error);
    }

    public function test_sending_replaces_placeholders_per_recipient(): void
    {
        Mail::fake();

        app(ChurchModuleManager::class)->updateSettings($this->church, 'messaging', [
            'email' => [
                'host' => 'smtp.test',
                'port' => 587,
                'username' => '',
                'password' => '',
                'encryption' => 'tls',
                'from_address' => 'church@test',
                'from_name' => 'Church',
            ],
        ]);

        $this->assignee->update(['display_name' => 'Ann <b>Smith</b>']);
        $taskName = $this->event->tasks()->first()->displayName();

        $message = app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: '<p>Hi {{ first_name }}, you have {{task}} at {{event}}. {{unknown}}</p>',
            subject: '{{task}} for {{name}}',
            recipientUserIds: [$this->assignee->id],
            sendNow: true,
        )->sole();

        $outbound = OutboundMessage::query()->where('event_message_id', $message->id)->sole();

        $this->assertSame($taskName.' for Ann <b>Smith</b>', $outbound->subject);
        $this->assertStringContainsString(
            '<p>Hi Ann, you have '.e($taskName).' at '.e($this->event->name).'. {{unknown}}</p>',
            $outbound->body,
        );
        $this->assertStringNotContainsString('<b>Smith</b>', $outbound->body);
    }

    public function test_sms_recipients_get_the_text_version_with_placeholders_and_reply_link(): void
    {
        Http::fake(['api.ip1sms.com/*' => Http::response(['id' => 'batch-1'], 200)]);

        $manager = app(ChurchModuleManager::class);
        $manager->enable($this->church, MessagingAvailability::MODULE_SMS, [
            'api_base' => 'https://api.ip1sms.com/v2',
            'api_token' => $manager->encryptSecret('test-token'),
            'sender' => 'Worship',
        ]);

        $this->assignee->update([
            'display_name' => 'Ann Smith',
            'phone' => '46701234567',
            'message_preference' => MessagePreference::Sms,
        ]);

        app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: '<p>HTML version</p>',
            subject: 'Note',
            recipientUserIds: [$this->assignee->id],
            sendNow: true,
            textBody: 'Hi {{first_name}}, text version.',
        );

        Http::assertSent(fn ($request): bool => str_starts_with($request['body'], "Hi Ann, text version.\n\nRespond to this invitation: http"));
    }

    public function test_text_only_message_is_escaped_for_email_recipients(): void
    {
        Mail::fake();

        app(ChurchModuleManager::class)->updateSettings($this->church, 'messaging', [
            'email' => [
                'host' => 'smtp.test',
                'from_address' => 'church@test',
            ],
        ]);

        $this->assignee->update(['display_name' => 'Ann <b>Smith</b>']);

        $message = app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: null,
            subject: 'Note',
            recipientUserIds: [$this->assignee->id],
            sendNow: true,
            textBody: "Hi {{name}}\nSee you",
        )->sole();

        $body = OutboundMessage::query()->where('event_message_id', $message->id)->sole()->body;

        $this->assertStringStartsWith('Hi Ann &lt;b&gt;Smith&lt;/b&gt;<br />'."\n".'See you', $body);
    }

    public function test_person_with_several_tasks_gets_one_message_per_task_with_its_own_links(): void
    {
        Mail::fake();
        $this->configureEmail();

        $secondTask = EventTask::factory()->create([
            'event_id' => $this->event->id,
            'task_template_id' => TaskTemplate::factory()->create(['church_id' => $this->church->id])->id,
            'assigned_user_id' => $this->assignee->id,
        ]);

        app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: '<p>{{task}}</p>{{accept_link}} {{decline_link}}',
            subject: '{{task}}',
            recipientUserIds: [$this->assignee->id],
            sendNow: true,
        );

        $outbound = OutboundMessage::query()->where('event_id', $this->event->id)->orderBy('id')->get();
        $links = app(AssignmentResponseLink::class);
        $tasks = $this->event->tasks()->orderBy('id')->get();

        $this->assertCount(2, $outbound);
        $this->assertSame(
            $tasks->pluck('id')->all(),
            EventMessage::query()->where('event_id', $this->event->id)->orderBy('id')->pluck('event_task_id')->all(),
        );

        foreach ($tasks as $index => $task) {
            $body = $outbound[$index]->body;

            $this->assertSame($task->displayName(), $outbound[$index]->subject);
            $this->assertStringContainsString('href="'.e($links->acceptUrl($task, $this->assignee)).'"', $body);
            $this->assertStringContainsString('href="'.e($links->declineUrl($task, $this->assignee)).'"', $body);
            $this->assertStringNotContainsString(__('Open response page'), $body);
        }

        $this->assertTrue($tasks->contains('id', $secondTask->id));
    }

    public function test_sms_built_from_html_keeps_the_link_addresses(): void
    {
        Http::fake(['api.ip1sms.com/*' => Http::response(['id' => 'batch-1'], 200)]);

        $manager = app(ChurchModuleManager::class);
        $manager->enable($this->church, MessagingAvailability::MODULE_SMS, [
            'api_base' => 'https://api.ip1sms.com/v2',
            'api_token' => $manager->encryptSecret('test-token'),
            'sender' => 'Worship',
        ]);

        $this->assignee->update(['phone' => '46701234567', 'message_preference' => MessagePreference::Sms]);

        app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: '<p>Can you come?</p><p>{{accept_link}}</p><p><a href="{{decline_url}}">Sorry, no</a></p>',
            subject: 'Note',
            recipientUserIds: [$this->assignee->id],
            sendNow: true,
        );

        $task = $this->event->tasks()->first();
        $links = app(AssignmentResponseLink::class);

        Http::assertSent(fn ($request): bool => str_contains($request['body'], $links->acceptUrl($task, $this->assignee))
            && str_contains($request['body'], 'Sorry, no: '.$links->declineUrl($task, $this->assignee)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function messageTemplate(array $attributes = []): MessageTemplate
    {
        return MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'name' => 'Note',
            'subject' => 'Note',
            'text_body' => 'Hello',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    protected function configureEmail(): void
    {
        app(ChurchModuleManager::class)->updateSettings($this->church, 'messaging', [
            'email' => [
                'host' => 'smtp.test',
                'from_address' => 'church@test',
            ],
        ]);
    }

    public function test_choosing_a_template_sends_it_exactly_as_saved(): void
    {
        $html = '<table><tr><td bgcolor="#90EE90" style="padding: 12px 24px;">'
            .'<a href="{{accept_url}}" style="color: #14532D; display: block;">Ja, jag kommer</a></td></tr></table>';

        $template = MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'name' => 'Demand reply',
            'subject' => 'Can you come?',
            'body' => $html,
            'text_body' => 'Ja: {{accept_link}}',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, [
            'record' => $this->event->getRouteKey(),
        ])
            ->callTableAction('create', data: [
                'message_template_id' => $template->id,
                'subject' => 'Typed subject',
                'text_body' => 'Typed text',
                'recipient_task_ids' => [$this->event->tasks()->first()->id],
            ])
            ->assertHasNoTableActionErrors();

        $message = EventMessage::query()->where('event_id', $this->event->id)->sole();

        $this->assertSame($html, $message->body);
        $this->assertSame('Ja: {{accept_link}}', $message->text_body);
        $this->assertSame('Can you come?', $message->subject);
        $this->assertSame($template->id, $message->message_template_id);
    }

    public function test_a_message_template_must_be_chosen(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, [
            'record' => $this->event->getRouteKey(),
        ])
            ->callTableAction('create', data: [
                'subject' => 'Rehearsal moved',
                'text_body' => 'Rehearsal starts at 18:00.',
                'recipient_task_ids' => [$this->event->tasks()->first()->id],
            ])
            ->assertHasTableActionErrors(['message_template_id' => 'required']);

        $this->assertSame(0, EventMessage::query()->count());
    }

    public function test_only_service_templates_can_be_chosen_for_a_service_message(): void
    {
        $this->actingAs($this->admin);

        foreach ([MessageTemplatePurpose::Team, MessageTemplatePurpose::Other] as $purpose) {
            Livewire::test(ManageEventMessages::class, [
                'record' => $this->event->getRouteKey(),
            ])
                ->callTableAction('create', data: [
                    'message_template_id' => $this->messageTemplate(['purpose' => $purpose])->id,
                    'recipient_task_ids' => [$this->event->tasks()->first()->id],
                ])
                ->assertHasTableActionErrors(['message_template_id']);
        }

        $this->assertSame(0, EventMessage::query()->count());
    }

    public function test_custom_message_replaces_the_custom_mess_placeholder(): void
    {
        $template = $this->messageTemplate([
            'body' => '<p>Hi {{first_name}}</p><p>{{custom_mess}}</p>',
            'text_body' => 'Hi {{first_name}}. {{custom_mess}}',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, [
            'record' => $this->event->getRouteKey(),
        ])
            ->callTableAction('create', data: [
                'message_template_id' => $template->id,
                'custom_message' => "Rehearsal starts at 18:00.\nBring <music>.",
                'recipient_task_ids' => [$this->event->tasks()->first()->id],
            ])
            ->assertHasNoTableActionErrors();

        $message = EventMessage::query()->where('event_id', $this->event->id)->sole();
        $composed = app(EventMessageService::class)->compose($message, $this->assignee, $this->event->tasks()->first());

        $this->assertSame("Rehearsal starts at 18:00.\nBring <music>.", $message->custom_message);
        $this->assertStringContainsString('<p>Rehearsal starts at 18:00.<br>Bring &lt;music&gt;.</p>', $composed->body);
        $this->assertStringContainsString("Hi Assignee. Rehearsal starts at 18:00.\nBring <music>.", $composed->plainText());
    }

    public function test_message_sender_is_the_person_who_created_the_message(): void
    {
        $this->admin->update(['display_name' => 'Erik Lund']);

        $message = app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: null,
            subject: 'From {{message_sender}}',
            recipientUserIds: [$this->assignee->id],
            textBody: 'Regards, {{message_sender}}. {{team}}',
        )->sole();

        $composed = app(EventMessageService::class)->compose($message, $this->assignee, $this->event->tasks()->first());

        $this->assertSame('From Erik Lund', $composed->subject);
        $this->assertStringContainsString('Regards, Erik Lund. {{team}}', $composed->plainText());
    }

    public function test_custom_message_is_required_when_the_template_has_the_placeholder(): void
    {
        $template = $this->messageTemplate(['text_body' => '{{custom_mess}}']);

        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, [
            'record' => $this->event->getRouteKey(),
        ])
            ->callTableAction('create', data: [
                'message_template_id' => $template->id,
                'recipient_task_ids' => [$this->event->tasks()->first()->id],
            ])
            ->assertHasTableActionErrors(['custom_message' => 'required']);

        $this->assertSame(0, EventMessage::query()->count());
    }

    public function test_messages_table_shows_each_recipients_answer(): void
    {
        app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: 'Hello team',
            subject: 'Note',
            recipientUserIds: [$this->assignee->id],
            sendNow: false,
        );

        $this->event->tasks()->first()->update(['accepted_verbally_at' => now()]);

        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, [
            'record' => $this->event->getRouteKey(),
        ])
            ->assertTableColumnExists('responses')
            ->assertSeeInOrder(['Assignee', $this->event->tasks()->first()->displayName(), __('Accepted verbally')]);
    }

    public function test_popup_lists_each_task_and_only_the_ticked_task_gets_a_message(): void
    {
        $firstTask = $this->event->tasks()->first();
        $secondTask = EventTask::factory()->create([
            'event_id' => $this->event->id,
            'task_template_id' => TaskTemplate::factory()->create(['church_id' => $this->church->id])->id,
            'assigned_user_id' => $this->assignee->id,
            'name' => 'Second job',
        ]);

        $this->assertSame([
            $firstTask->id => 'Assignee — '.$firstTask->displayName(),
            $secondTask->id => 'Assignee — Second job',
        ], $this->event->personTaskOptions()->sortKeys()->all());

        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, [
            'record' => $this->event->getRouteKey(),
        ])
            ->callTableAction('create', data: [
                'message_template_id' => $this->messageTemplate()->id,
                'recipient_task_ids' => [$secondTask->id],
            ])
            ->assertHasNoTableActionErrors();

        $message = EventMessage::query()->where('event_id', $this->event->id)->sole();

        $this->assertSame($secondTask->id, $message->event_task_id);
        $this->assertSame([$this->assignee->id], $message->recipients()->pluck('users.id')->all());
    }

    public function test_popup_only_lists_tasks_that_have_not_been_accepted(): void
    {
        app(ChurchModuleManager::class)->enable($this->church, 'teams');
        $accepted = $this->event->tasks()->first();
        $accepted->update(['name' => 'Accepted position']);
        app(AssignmentResponder::class)->confirm($accepted, $this->assignee);
        EventTask::factory()->create([
            'event_id' => $this->event->id,
            'task_template_id' => $accepted->task_template_id,
            'assigned_user_id' => $this->assignee->id,
            'name' => 'Unanswered position for same person',
        ]);
        EventTask::factory()->create([
            'event_id' => $this->event->id,
            'task_template_id' => $accepted->task_template_id,
            'assigned_user_id' => $this->outsider->id,
            'name' => 'Verbally accepted position',
            'accepted_verbally_at' => now(),
        ]);
        $team = Team::factory()->create(['church_id' => $this->church->id]);
        $teamTask = EventTask::factory()->create([
            'event_id' => $this->event->id,
            'task_template_id' => $accepted->task_template_id,
            'assigned_user_id' => null,
            'assigned_team_id' => $team->id,
            'name' => 'Accepted team position',
        ]);
        EventTaskResponse::factory()->create([
            'church_id' => $this->church->id,
            'event_task_id' => $teamTask->id,
            'assignee_team_id' => $team->id,
            'status' => AssignmentResponseStatus::Confirmed,
        ]);
        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, ['record' => $this->event->getRouteKey()])
            ->mountAction(TestAction::make('create')->table())
            ->assertMountedActionModalSee('Unanswered position for same person')
            ->assertMountedActionModalDontSee(['Accepted position', 'Verbally accepted position', 'Accepted team position']);
    }

    public function test_sending_rechecks_acceptance_and_ignores_stale_selected_tasks(): void
    {
        $accepted = $this->event->tasks()->first();
        $pending = EventTask::factory()->create([
            'event_id' => $this->event->id,
            'task_template_id' => $accepted->task_template_id,
            'assigned_user_id' => $this->outsider->id,
        ]);
        $this->event->load('tasks.responses');
        app(AssignmentResponder::class)->confirm($accepted, $this->assignee);
        $this->actingAs($this->admin);

        EventMessageForm::create($this->event, [
            'message_template_id' => $this->messageTemplate()->id,
            'recipient_task_ids' => [$accepted->id, $pending->id],
        ]);

        $message = EventMessage::query()->sole();
        $this->assertSame($pending->id, $message->event_task_id);
        $this->assertSame([$this->outsider->id], $message->recipients()->pluck('users.id')->all());
    }

    public function test_sending_rejects_a_selection_when_every_task_has_been_accepted(): void
    {
        $task = $this->event->tasks()->first();
        app(AssignmentResponder::class)->confirm($task, $this->assignee);
        $this->actingAs($this->admin);

        try {
            EventMessageForm::create($this->event, [
                'message_template_id' => $this->messageTemplate()->id,
                'recipient_task_ids' => [$task->id],
            ]);
            $this->fail('Accepted tasks must not receive another invitation.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'recipient_task_ids' => [__('Select at least one task that has not been accepted.')],
            ], $exception->errors());
        }

        $this->assertSame(0, EventMessage::query()->count());

        Livewire::test(ManageEventMessages::class, ['record' => $this->event->getRouteKey()])
            ->mountAction(TestAction::make('create')->table())
            ->assertMountedActionModalSee('No assignments awaiting acceptance.');
    }

    public function test_table_shows_the_task_and_the_latest_sent_time(): void
    {
        Mail::fake();
        $this->configureEmail();

        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(9, 15));

        $message = app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: 'Hello',
            subject: 'Note',
            recipientUserIds: [$this->assignee->id],
            sendNow: true,
        )->sole();

        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(18, 40));
        app(EventMessageService::class)->resend($message);

        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, [
            'record' => $this->event->getRouteKey(),
        ])
            ->assertTableColumnStateSet('task_name', $this->event->tasks()->first()->displayName(), $message)
            ->assertSee('3 Oct 2026 18:40')
            ->assertDontSee('1 Oct 2026 09:15');
    }

    public function test_message_for_a_reassigned_task_fails_instead_of_sending(): void
    {
        Mail::fake();
        $this->configureEmail();

        $message = app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: 'Hello',
            subject: 'Note',
            recipientUserIds: [$this->assignee->id],
        )->sole();

        $this->event->tasks()->first()->update(['assigned_user_id' => $this->outsider->id]);

        $sent = app(EventMessageService::class)->send($message);

        $this->assertSame(EventMessage::STATUS_FAILED, $sent->status);
        $this->assertSame(__('The task is no longer assigned to this person.'), $sent->recipients->first()->pivot->delivery_error);
        Mail::assertNothingSent();
    }

    public function test_creating_a_message_sends_it_immediately_and_there_is_no_separate_send_action(): void
    {
        Mail::fake();
        $this->configureEmail();

        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, [
            'record' => $this->event->getRouteKey(),
        ])
            ->callTableAction('create', data: [
                'message_template_id' => $this->messageTemplate()->id,
                'recipient_task_ids' => [$this->event->tasks()->first()->id],
            ])
            ->assertHasNoTableActionErrors()
            ->assertTableActionDoesNotExist('send');

        $message = EventMessage::query()->where('event_id', $this->event->id)->sole();

        $this->assertSame(EventMessage::STATUS_SENT, $message->status);
        Mail::assertSentCount(1);
    }

    public function test_preview_shows_the_message_with_placeholders_filled_in(): void
    {
        $message = app(EventMessageService::class)->create(
            event: $this->event,
            author: $this->admin,
            body: '<p>Hi {{first_name}}, you have {{task}}.</p>{{accept_link}}',
            subject: '{{task}} on {{event}}',
            recipientUserIds: [$this->assignee->id],
            textBody: 'Hi {{first_name}}: {{accept_link}}',
        )->sole();

        $task = $this->event->tasks()->first();
        $acceptUrl = app(AssignmentResponseLink::class)->acceptUrl($task, $this->assignee);

        $this->actingAs($this->admin);

        Livewire::test(ManageEventMessages::class, [
            'record' => $this->event->getRouteKey(),
        ])
            ->mountAction(TestAction::make('viewBody')->table($message))
            ->assertMountedActionModalSee([
                'Hi Assignee, you have '.$task->displayName().'.',
                $task->displayName().' on '.$this->event->name,
                'Hi Assignee: '.$acceptUrl,
                __('Yes, I can help'),
            ])
            ->assertMountedActionModalDontSee('{{first_name}}');
    }
}
