<?php

namespace Tests\Feature\Messaging;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\MessagePreference;
use App\Filament\Resources\MessageTemplates\Pages\CreateMessageTemplate;
use App\Models\AssignmentResponseToken;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventMessage;
use App\Models\EventTask;
use App\Models\MessageTemplate;
use App\Models\TaskTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Messaging\Data\OutboundMessageData;
use Modules\Messaging\Mail\GenericMessageMail;
use Modules\Messaging\Services\EventMessageService;
use Modules\Messaging\Services\MessageSender;
use Modules\Messaging\Services\TemplateMessages;
use Modules\Messaging\Support\MessagingAvailability;
use Tests\TestCase;

class WhatsAppTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected const TEMPLATES_URL = 'graph.facebook.com/v21.0/111222333/message_templates*';

    protected const MESSAGES_URL = 'graph.facebook.com/v21.0/123456789/messages';

    protected Church $church;

    protected User $admin;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = app(ChurchModuleManager::class);
        $manager->enable($this->church, MessagingAvailability::MODULE_EMAIL);
        $manager->enable($this->church, MessagingAvailability::MODULE_WHATSAPP, [
            'api_version' => 'v21.0',
            'access_token' => $manager->encryptSecret('wa-token'),
            'phone_number_id' => '123456789',
            'business_account_id' => '111222333',
        ]);

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        $this->member = User::factory()->create([
            'church_id' => $this->church->id,
            'phone' => '0701234567',
            'message_preference' => MessagePreference::WhatsApp,
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->member, 'Member');

        app(CurrentChurch::class)->set($this->church);
    }

    public function test_event_message_from_a_linked_template_is_sent_as_the_whatsapp_template_with_answer_buttons(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TEMPLATES_URL => Http::response(['data' => [$this->requestReplyTemplate('APPROVED')]]),
            self::MESSAGES_URL => Http::response(['messages' => [['id' => 'wamid.1']]]),
        ]);
        $event = Event::factory()->create(['church_id' => $this->church->id, 'name' => 'Sunday service']);
        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => TaskTemplate::factory()->create(['church_id' => $this->church->id])->id,
            'assigned_user_id' => $this->member->id,
        ]);
        $template = MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'name' => 'Request reply',
            'text_body' => 'Free text that WhatsApp does not use',
            'whatsapp_template' => 'krava_svar',
            'is_active' => true,
        ]);

        app(EventMessageService::class)->create(
            event: $event,
            author: $this->admin,
            body: null,
            subject: 'Can you come?',
            recipientUserIds: [$this->member->id],
            templateId: $template->id,
            sendNow: true,
            textBody: $template->text_body,
        );

        $token = AssignmentResponseToken::query()->where('event_task_id', $task->id)->sole()->token;
        $this->assertSame(EventMessage::STATUS_SENT, EventMessage::query()->sole()->status);
        Http::assertSent(function (Request $request) use ($task, $token): bool {
            if (! str_ends_with($request->url(), '/123456789/messages')) {
                return false;
            }

            $components = collect($request['template']['components'])->keyBy(fn (array $c): string => $c['type'].($c['index'] ?? ''));

            return $request['to'] === '46701234567'
                && $request['type'] === 'template'
                && $request['template']['name'] === 'krava_svar'
                && $request['template']['language'] === ['code' => 'sv']
                && collect($components['body']['parameters'])->pluck('text', 'parameter_name')->only(['task', 'event'])->all()
                    === ['task' => $task->displayName(), 'event' => 'Sunday service']
                && $components['button0']['parameters'][0]['text'] === $token.'/yes'
                && $components['button1']['parameters'][0]['text'] === $token.'/no';
        });
    }

    public function test_custom_message_from_the_meta_template_is_asked_for_and_sent_on_one_line(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TEMPLATES_URL => Http::response(['data' => [[
                'name' => 'meddelande',
                'language' => 'sv',
                'status' => 'APPROVED',
                'components' => [['type' => 'BODY', 'text' => 'Hej {{first_name}}! {{custom_mess}}']],
            ]]]),
            self::MESSAGES_URL => Http::response(['messages' => [['id' => 'wamid.1']]]),
        ]);
        $template = MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'name' => 'Message',
            'text_body' => 'Hello',
            'whatsapp_template' => 'meddelande',
            'is_active' => true,
        ]);

        $this->assertTrue(app(TemplateMessages::class)->usesCustomMessage($template));

        $result = app(MessageSender::class)->sendToUser(
            $this->member,
            app(TemplateMessages::class)->compose($template, $this->member, "Rehearsal at 18:00.\n\nBring    your stand."),
        );

        $this->assertTrue($result->success);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages')
            && collect($request['template']['components'][0]['parameters'])->pluck('text', 'parameter_name')->get('custom_mess')
                === 'Rehearsal at 18:00. Bring   your stand.');
    }

    public function test_message_template_can_be_saved_without_a_meta_template_while_whatsapp_is_on(): void
    {
        Http::fake([self::TEMPLATES_URL => Http::response(['data' => [$this->requestReplyTemplate('APPROVED')]])]);
        $this->actingAs($this->admin);

        Livewire::test(CreateMessageTemplate::class)
            ->fillForm([
                'name' => 'Request reply',
                'text_body' => 'Can you come?',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(MessageTemplate::query()->sole()->whatsapp_template);
    }

    public function test_message_without_a_meta_template_goes_by_email_to_someone_who_prefers_whatsapp(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        app(ChurchModuleManager::class)->enable($this->church, MessagingAvailability::MODULE_EMAIL, [
            'email' => ['host' => 'smtp.church.test', 'port' => 587, 'from_address' => 'noreply@church.test'],
        ]);

        $result = app(MessageSender::class)->sendToUser(
            $this->member,
            new OutboundMessageData(subject: 'Can you come?', body: 'Can you come?'),
        );

        $this->assertSame(MessagePreference::Email->value, $result->channel);
        Http::assertNothingSent();
        Mail::assertSent(GenericMessageMail::class, fn (GenericMessageMail $mail): bool => $mail->hasTo($this->member->email));
    }

    public function test_template_not_yet_approved_fails_without_sending(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TEMPLATES_URL => Http::response(['data' => [$this->requestReplyTemplate('PENDING')]]),
        ]);

        $result = app(MessageSender::class)->sendToUser($this->member, $this->templateMessage(withLinks: true));

        $this->assertFalse($result->success);
        $this->assertSame('WhatsApp template "krava_svar" is not approved by Meta yet (status: PENDING).', $result->error);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages'));
    }

    public function test_send_reports_metas_error_when_templates_cannot_be_loaded(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TEMPLATES_URL => Http::response(['error' => ['message' => 'API access blocked.', 'code' => 200]], 400),
        ]);

        $result = app(MessageSender::class)->sendToUser($this->member, $this->templateMessage(withLinks: true));

        $this->assertFalse($result->success);
        $this->assertStringStartsWith('WhatsApp templates could not be loaded: WhatsApp API failed (400):', $result->error);
        $this->assertStringContainsString('API access blocked.', $result->error);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages'));
    }

    public function test_message_template_form_shows_metas_error_when_templates_cannot_be_loaded(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TEMPLATES_URL => Http::response(['error' => ['message' => 'API access blocked.', 'code' => 200]], 400),
        ]);
        $this->actingAs($this->admin);

        Livewire::test(CreateMessageTemplate::class)
            ->assertSee('WhatsApp templates could not be loaded')
            ->assertSee('API access blocked.');
    }

    public function test_template_with_answer_buttons_fails_when_the_message_has_no_task(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TEMPLATES_URL => Http::response(['data' => [$this->requestReplyTemplate('APPROVED')]]),
        ]);

        $result = app(MessageSender::class)->sendToUser($this->member, $this->templateMessage(withLinks: false));

        $this->assertFalse($result->success);
        $this->assertSame('The WhatsApp template has link buttons, but this message has no task to answer.', $result->error);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages'));
    }

    public function test_message_template_form_saves_the_chosen_whatsapp_template(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TEMPLATES_URL => Http::response(['data' => [$this->requestReplyTemplate('APPROVED')]]),
        ]);
        $this->actingAs($this->admin);

        Livewire::test(CreateMessageTemplate::class)
            ->fillForm([
                'name' => 'Request reply',
                'text_body' => 'Can you come?',
                'whatsapp_template' => 'krava_svar',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('krava_svar', MessageTemplate::query()->sole()->whatsapp_template);
    }

    /**
     * @return array<string, mixed>
     */
    protected function requestReplyTemplate(string $status): array
    {
        return [
            'name' => 'krava_svar',
            'language' => 'sv',
            'status' => $status,
            'components' => [
                ['type' => 'BODY', 'text' => 'Du har {{task}} på {{event}} {{date}} kl. {{time}}.'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'text' => 'Ja, jag kommer', 'url' => 'https://planner.example.se/r/{{1}}'],
                    ['type' => 'URL', 'text' => 'Nej, jag kommer inte', 'url' => 'https://planner.example.se/r/{{1}}'],
                ]],
            ],
        ];
    }

    protected function templateMessage(bool $withLinks): OutboundMessageData
    {
        return new OutboundMessageData(
            subject: 'Can you come?',
            body: 'Can you come?',
            whatsappTemplate: 'krava_svar',
            placeholders: [
                'task' => 'Sound',
                'event' => 'Sunday service',
                'date' => 'söndag 1 november 2026',
                'time' => '11:00',
                'accept_url' => $withLinks ? 'http://localhost/r/abc23def45gh/yes' : '',
                'decline_url' => $withLinks ? 'http://localhost/r/abc23def45gh/no' : '',
            ],
        );
    }
}
