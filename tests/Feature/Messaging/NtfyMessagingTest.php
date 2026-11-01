<?php

namespace Tests\Feature\Messaging;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Enums\MessagePreference;
use App\Enums\PasswordLinkKind;
use App\Filament\Forms\NtfyTopicField;
use App\Filament\Pages\EditProfile;
use App\Filament\Pages\NtfyMessagingSettings;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\Language;
use App\Models\MessageTemplate;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Services\InvitationDeliveryPreview;
use App\Services\UserInviter;
use Database\Seeders\LanguagesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Messaging\Data\OutboundMessageData;
use Modules\Messaging\Services\MessageSender;
use Modules\Messaging\Support\MessagingAvailability;
use Tests\TestCase;

class NtfyMessagingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected const SERVER_URL = 'https://ntfy.sh/';

    protected const TOPIC = 'worship-k3j9x2qp7m4tz8w1bq5n';

    protected Church $church;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed([RolesAndPermissionsSeeder::class, LanguagesSeeder::class]);

        $confirmation = MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'name' => 'ntfy confirmation',
            'purpose' => 'other',
            'text_body' => 'Hi {{first_name}}, ntfy works!',
            'is_active' => true,
        ]);

        $manager = app(ChurchModuleManager::class);
        $manager->enable($this->church, MessagingAvailability::MODULE_EMAIL);
        $manager->enable($this->church, MessagingAvailability::MODULE_NTFY, [
            'server_url' => 'https://ntfy.sh',
            'access_token' => '',
            'confirmation_template_id' => $confirmation->id,
        ]);

        $this->user = User::factory()->create([
            'church_id' => $this->church->id,
            'display_name' => 'Anna Svensson',
            'message_preference' => MessagePreference::Email,
            'language_id' => Language::query()->where('iso', 'en')->value('id'),
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->user, 'Member');

        app(CurrentChurch::class)->set($this->church);
        $this->actingAs($this->user);
    }

    public function test_channel_posts_the_text_and_subject_to_the_users_topic_without_a_token(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SERVER_URL => Http::response(['id' => 'msg-1', 'event' => 'message'], 200)]);
        $this->user->update(['message_preference' => MessagePreference::Ntfy, 'ntfy_topic' => self::TOPIC]);

        $result = app(MessageSender::class)->sendToUser(
            $this->user->fresh(),
            new OutboundMessageData(subject: 'Sunday', body: '<p>Hello <strong>team</strong></p>'),
        );

        $this->assertTrue($result->success);
        $this->assertSame('ntfy', $result->channel);
        $this->assertSame('msg-1', $result->providerMessageId);
        Http::assertSent(fn ($request): bool => $request->url() === self::SERVER_URL
            && $request['topic'] === self::TOPIC
            && $request['title'] === 'Sunday'
            && $request['message'] === 'Hello team'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_channel_sends_the_access_token_to_the_churchs_own_server(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://ntfy.example.org/' => Http::response(['id' => 'msg-1'], 200)]);
        $manager = app(ChurchModuleManager::class);
        $manager->updateSettings($this->church, MessagingAvailability::MODULE_NTFY, [
            'server_url' => 'https://ntfy.example.org',
            'access_token' => $manager->encryptSecret('tk_secret'),
        ]);
        $this->user->update(['message_preference' => MessagePreference::Ntfy, 'ntfy_topic' => self::TOPIC]);

        $result = app(MessageSender::class)->sendToUser($this->user->fresh(), new OutboundMessageData(subject: '', body: 'Hello'));

        $this->assertTrue($result->success);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://ntfy.example.org/'
            && $request->hasHeader('Authorization', 'Bearer tk_secret'));
    }

    public function test_channel_fails_without_a_topic_and_sends_nothing(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->user->update(['message_preference' => MessagePreference::Ntfy]);

        $result = app(MessageSender::class)->sendToUser(
            $this->user->fresh(),
            new OutboundMessageData(subject: '', body: 'Hello'),
        );

        $this->assertFalse($result->success);
        $this->assertSame('Recipient has no ntfy topic.', $result->error);
        Http::assertNothingSent();
    }

    public function test_topic_field_shows_only_while_ntfy_is_chosen(): void
    {
        Livewire::test(EditProfile::class)
            ->assertFormFieldHidden('ntfy_topic')
            ->fillForm(['message_preference' => MessagePreference::Ntfy->value])
            ->assertFormFieldVisible('ntfy_topic');
    }

    public function test_choosing_ntfy_warns_to_enter_the_churchs_own_server_in_the_app(): void
    {
        app(ChurchModuleManager::class)->updateSettings($this->church, MessagingAvailability::MODULE_NTFY, [
            'server_url' => 'https://ntfy.example.org',
            'access_token' => '',
        ]);

        Livewire::test(EditProfile::class)
            ->assertDontSee('Set the server in the ntfy app')
            ->fillForm(['message_preference' => MessagePreference::Ntfy->value])
            ->assertSee('Set the server in the ntfy app')
            ->assertSeeHtml('<strong>https://ntfy.example.org</strong>');
    }

    public function test_choosing_ntfy_shows_links_to_install_the_app(): void
    {
        Livewire::test(EditProfile::class)
            ->assertDontSeeHtml(NtfyTopicField::APP_STORE_URL)
            ->fillForm(['message_preference' => MessagePreference::Ntfy->value])
            ->assertSeeHtml(NtfyTopicField::APP_STORE_URL)
            ->assertSeeHtml(NtfyTopicField::GOOGLE_PLAY_URL);
    }

    public function test_choosing_ntfy_makes_a_long_random_topic(): void
    {
        $component = Livewire::test(EditProfile::class)
            ->fillForm(['message_preference' => MessagePreference::Ntfy->value]);

        $this->assertMatchesRegularExpression('/^worship-[a-z0-9]{20}$/', $component->get('data.ntfy_topic'));
    }

    public function test_choosing_ntfy_again_keeps_the_saved_topic(): void
    {
        $this->user->update(['ntfy_topic' => self::TOPIC]);

        $component = Livewire::test(EditProfile::class)
            ->fillForm(['message_preference' => MessagePreference::Ntfy->value]);

        $this->assertSame(self::TOPIC, $component->get('data.ntfy_topic'));
    }

    public function test_topic_button_replaces_the_topic_with_a_new_random_one(): void
    {
        $this->user->update(['message_preference' => MessagePreference::Ntfy, 'ntfy_topic' => self::TOPIC]);

        $component = Livewire::test(EditProfile::class)
            ->callAction(TestAction::make('generateNtfyTopic')->schemaComponent('ntfy_topic'));

        $topic = $component->get('data.ntfy_topic');
        $this->assertNotSame(self::TOPIC, $topic);
        $this->assertMatchesRegularExpression('/^worship-[a-z0-9]{20}$/', $topic);
    }

    public function test_choosing_ntfy_requires_a_topic(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SERVER_URL => Http::response(['code' => 42901, 'http' => 429, 'error' => 'limit reached: too many requests'], 429)]);

        $this->saveProfile(['message_preference' => MessagePreference::Ntfy->value, 'ntfy_topic' => ''])
            ->assertHasFormErrors(['ntfy_topic' => 'required']);

        $this->assertSame(MessagePreference::Email, $this->user->fresh()->message_preference);
    }

    public function test_choosing_ntfy_rejects_a_topic_the_app_did_not_make(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SERVER_URL => Http::response(['code' => 42901, 'http' => 429, 'error' => 'limit reached: too many requests'], 429)]);

        $this->saveProfile(['message_preference' => MessagePreference::Ntfy->value, 'ntfy_topic' => 'anna-svensson-2024'])
            ->assertHasFormErrors(['ntfy_topic' => 'regex']);

        $this->assertNull($this->user->fresh()->ntfy_topic);
    }

    public function test_choosing_ntfy_rejects_a_topic_someone_else_already_has(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SERVER_URL => Http::response(['id' => 'msg-1'], 200)]);
        User::factory()->create(['church_id' => $this->church->id, 'ntfy_topic' => self::TOPIC]);

        $this->saveProfile(['message_preference' => MessagePreference::Ntfy->value, 'ntfy_topic' => self::TOPIC])
            ->assertHasFormErrors(['ntfy_topic' => 'unique']);

        $this->assertNull($this->user->fresh()->ntfy_topic);
    }

    public function test_choosing_ntfy_sends_the_confirmation_and_saves_the_topic(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SERVER_URL => Http::response(['id' => 'msg-1', 'event' => 'message'], 200)]);

        $this->saveProfile(['message_preference' => MessagePreference::Ntfy->value, 'ntfy_topic' => self::TOPIC])
            ->assertHasNoFormErrors();

        $user = $this->user->fresh();
        $this->assertSame(MessagePreference::Ntfy, $user->message_preference);
        $this->assertSame(self::TOPIC, $user->ntfy_topic);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['topic'] === self::TOPIC
            && $request['message'] === 'Hi Anna, ntfy works!');
    }

    public function test_choosing_ntfy_is_refused_when_the_server_rejects_the_message(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SERVER_URL => Http::response(['code' => 42901, 'http' => 429, 'error' => 'limit reached: too many requests'], 429)]);

        $this->saveProfile(['message_preference' => MessagePreference::Ntfy->value, 'ntfy_topic' => self::TOPIC])
            ->assertHasFormErrors(['message_preference']);

        $user = $this->user->fresh();
        $this->assertSame(MessagePreference::Email, $user->message_preference);
        $this->assertNull($user->ntfy_topic);
    }

    public function test_invite_goes_by_ntfy_from_the_chosen_template(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SERVER_URL => Http::response(['id' => 'msg-1', 'event' => 'message'], 200)]);
        Notification::fake();
        $template = MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'name' => 'Set password',
            'purpose' => 'other',
            'text_body' => 'Choose a password: {{password_url}}',
            'is_active' => true,
        ]);
        $manager = app(ChurchModuleManager::class);
        $manager->updateSettings($this->church, MessagingAvailability::MODULE_NTFY, [
            ...$manager->settings($this->church, MessagingAvailability::MODULE_NTFY),
            PasswordLinkKind::SetPassword->templateSettingKey() => $template->id,
        ]);
        $this->user->update(['message_preference' => MessagePreference::Ntfy, 'ntfy_topic' => self::TOPIC]);

        app(UserInviter::class)->send($this->user->fresh());

        Http::assertSent(fn ($request): bool => $request['topic'] === self::TOPIC
            && str_starts_with($request['message'], 'Choose a password: http')
            && str_contains($request['message'], 'password-reset'));
        Notification::assertNotSentTo($this->user, UserInvitationNotification::class);
    }

    public function test_delivery_preview_flags_a_missing_topic(): void
    {
        $this->user->update(['message_preference' => MessagePreference::Ntfy]);
        $event = Event::factory()->create(['church_id' => $this->church->id]);
        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => TaskTemplate::factory()->create([
                'church_id' => $this->church->id,
                'person_usage' => FieldUsage::Required,
                'team_usage' => FieldUsage::NotUsed,
            ])->id,
            'assigned_user_id' => $this->user->id,
        ]);

        $rows = app(InvitationDeliveryPreview::class)->resolve($event, [$this->user->id]);

        $this->assertFalse($rows[0]['can_deliver']);
        $this->assertSame('No ntfy topic', $rows[0]['detail']);
    }

    public function test_settings_keep_the_saved_token_when_left_blank_and_save_the_server_and_confirmation_template(): void
    {
        $manager = app(ChurchModuleManager::class);
        $manager->updateSettings($this->church, MessagingAvailability::MODULE_NTFY, [
            'server_url' => 'https://ntfy.sh',
            'access_token' => $manager->encryptSecret('tk_secret'),
        ]);
        $admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        $this->actingAs($admin);
        $template = MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'name' => 'Another confirmation',
            'purpose' => 'other',
            'text_body' => 'Works',
            'is_active' => true,
        ]);

        Livewire::test(NtfyMessagingSettings::class)
            ->fillForm(['server_url' => 'https://ntfy.example.org/', 'access_token' => '', 'confirmation_template_id' => $template->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = $manager->settings($this->church, MessagingAvailability::MODULE_NTFY);
        $this->assertSame('https://ntfy.example.org', $settings['server_url']);
        $this->assertSame('tk_secret', $manager->decryptSecret($settings['access_token']));
        $this->assertSame($template->id, $settings['confirmation_template_id']);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    protected function saveProfile(array $changes): mixed
    {
        return Livewire::test(EditProfile::class)
            ->fillForm([
                'name' => $this->user->name,
                'display_name' => $this->user->display_name,
                'email' => $this->user->email,
                'language_id' => $this->user->language_id,
                'message_preference' => $this->user->message_preference->value,
                ...$changes,
            ])
            ->call('save');
    }
}
