<?php

namespace Tests\Feature\Messaging;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Enums\MessagePreference;
use App\Filament\Pages\EditProfile;
use App\Filament\Pages\TelegramMessagingSettings;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\Language;
use App\Models\MessageTemplate;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\InvitationDeliveryPreview;
use Database\Seeders\LanguagesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Messaging\Data\OutboundMessageData;
use Modules\Messaging\Services\MessageSender;
use Modules\Messaging\Support\MessagingAvailability;
use Modules\Messaging\Support\TelegramLinks;
use Tests\TestCase;

class TelegramMessagingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected const BOT_TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw0';

    protected const API = 'https://api.telegram.org/bot'.self::BOT_TOKEN.'/';

    protected const SECRET = 'webhook-secret-for-tests';

    protected Church $church;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create(['name' => 'Grace Church']);
        $this->seed([RolesAndPermissionsSeeder::class, LanguagesSeeder::class]);

        $confirmation = MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'name' => 'Telegram confirmation',
            'purpose' => 'other',
            'text_body' => 'Hi {{first_name}}, Telegram works!',
            'is_active' => true,
        ]);

        $manager = app(ChurchModuleManager::class);
        $manager->enable($this->church, MessagingAvailability::MODULE_EMAIL);
        $manager->enable($this->church, MessagingAvailability::MODULE_TELEGRAM, [
            'bot_token' => $manager->encryptSecret(self::BOT_TOKEN),
            'bot_username' => 'GraceChurchBot',
            'webhook_secret' => self::SECRET,
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

    public function test_channel_sends_the_bold_subject_and_escaped_text_to_the_persons_chat(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::API.'sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
        $this->connect($this->user, prefer: true);

        $result = app(MessageSender::class)->sendToUser(
            $this->user->fresh(),
            new OutboundMessageData(subject: 'Sunday <9:00>', body: '', textBody: 'Bread & wine'),
        );

        $this->assertTrue($result->success);
        $this->assertSame('telegram', $result->channel);
        $this->assertSame('77', $result->providerMessageId);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '555001'
            && $request['text'] === "<b>Sunday &lt;9:00&gt;</b>\n\nBread &amp; wine"
            && $request['parse_mode'] === 'HTML'
            && ! isset($request['reply_markup']));
    }

    public function test_channel_adds_yes_and_no_buttons_for_invitations(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::API.'sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->connect($this->user, prefer: true);

        app(MessageSender::class)->sendToUser($this->user->fresh(), new OutboundMessageData(
            subject: 'Sunday',
            body: 'Can you help?',
            placeholders: ['accept_url' => 'https://planner.test/r/abc/yes', 'decline_url' => 'https://planner.test/r/abc/no'],
        ));

        Http::assertSent(fn (Request $request): bool => $request['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://planner.test/r/abc/yes'
            && $request['reply_markup']['inline_keyboard'][0][1]['url'] === 'https://planner.test/r/abc/no');
    }

    public function test_channel_disconnects_a_person_who_blocked_the_bot(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::API.'sendMessage' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
        $this->connect($this->user, prefer: true);

        $result = app(MessageSender::class)->sendToUser($this->user->fresh(), new OutboundMessageData(subject: '', body: 'Hello'));

        $this->assertFalse($result->success);
        $this->assertNull($this->user->fresh()->telegram_chat_id);
    }

    public function test_channel_fails_without_a_connected_chat_and_sends_nothing(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->user->update(['message_preference' => MessagePreference::Telegram]);

        $result = app(MessageSender::class)->sendToUser($this->user->fresh(), new OutboundMessageData(subject: '', body: 'Hello'));

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_channel_keeps_the_bot_token_out_of_connection_errors(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for '.self::API.'sendMessage'));
        $this->connect($this->user, prefer: true);

        $result = app(MessageSender::class)->sendToUser($this->user->fresh(), new OutboundMessageData(subject: '', body: 'Hello'));

        $this->assertFalse($result->success);
        $this->assertStringNotContainsString(self::BOT_TOKEN, (string) $result->error);
    }

    public function test_webhook_refuses_requests_without_the_secret(): void
    {
        $token = $this->linkToken();

        $this->postJson(route('telegram.webhook', $this->church), $this->startUpdate($token), ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])
            ->assertForbidden();

        $this->assertNull($this->user->fresh()->telegram_chat_id);
    }

    public function test_start_with_a_connect_link_connects_the_person_once(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::API.'sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $token = $this->linkToken();

        $this->postWebhook($this->startUpdate($token))->assertOk();

        $user = $this->user->fresh();
        $this->assertSame('555001', $user->telegram_chat_id);
        $this->assertSame('anna_s', $user->telegram_username);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '555001' && str_starts_with($request['text'], 'Connected!'));

        $user->forceFill(['telegram_chat_id' => null])->save();
        $this->postWebhook($this->startUpdate($token, chatId: 999))->assertOk();

        $this->assertNull($this->user->fresh()->telegram_chat_id);
    }

    public function test_start_with_a_link_for_another_churchs_person_connects_nobody(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::API.'sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $other = User::factory()->create(['church_id' => $this->church->id]);
        $token = $this->linkToken($other);
        $other->update(['church_id' => Church::factory()->create()->id]);

        $this->postWebhook($this->startUpdate($token))->assertOk();

        $this->assertNull($other->fresh()->telegram_chat_id);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request['text'], 'This link has expired.'));
    }

    public function test_blocking_the_bot_disconnects_the_person(): void
    {
        $this->connect($this->user);

        $this->postWebhook([
            'update_id' => 2,
            'my_chat_member' => [
                'chat' => ['id' => 555001, 'type' => 'private'],
                'new_chat_member' => ['status' => 'kicked'],
            ],
        ])->assertOk();

        $this->assertNull($this->user->fresh()->telegram_chat_id);
    }

    public function test_choosing_telegram_shows_the_connect_link(): void
    {
        Livewire::test(EditProfile::class)
            ->assertDontSeeHtml('https://t.me/GraceChurchBot?start=')
            ->fillForm(['message_preference' => MessagePreference::Telegram->value])
            ->assertSeeHtml('https://t.me/GraceChurchBot?start=');
    }

    public function test_choosing_telegram_is_refused_until_the_person_has_connected(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->saveProfile(['message_preference' => MessagePreference::Telegram->value])
            ->assertHasFormErrors(['message_preference']);

        $this->assertSame(MessagePreference::Email, $this->user->fresh()->message_preference);
        Http::assertNothingSent();
    }

    public function test_choosing_telegram_after_connecting_sends_the_confirmation_and_saves(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::API.'sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $component = Livewire::test(EditProfile::class);
        $this->connect($this->user);

        $component->fillForm(['message_preference' => MessagePreference::Telegram->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(MessagePreference::Telegram, $this->user->fresh()->message_preference);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '555001'
            && str_ends_with($request['text'], "\n\nHi Anna, Telegram works!"));
    }

    public function test_delivery_preview_flags_a_person_who_has_not_connected(): void
    {
        $this->user->update(['message_preference' => MessagePreference::Telegram]);
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
        $this->assertSame('Telegram not connected', $rows[0]['detail']);
    }

    public function test_settings_check_a_new_bot_set_its_webhook_and_disconnect_people_from_the_old_bot(): void
    {
        $newToken = '987654321:BBHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw9';
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot'.$newToken.'/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'NewGraceBot']]),
            'https://api.telegram.org/bot'.$newToken.'/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);
        $this->connect($this->user);
        $this->actAsAdmin();

        Livewire::test(TelegramMessagingSettings::class)
            ->fillForm(['bot_token' => $newToken])
            ->call('save')
            ->assertHasNoFormErrors();

        $manager = app(ChurchModuleManager::class);
        $settings = $manager->settings($this->church, MessagingAvailability::MODULE_TELEGRAM);
        $this->assertSame($newToken, $manager->decryptSecret($settings['bot_token']));
        $this->assertSame('NewGraceBot', $settings['bot_username']);
        $this->assertNull($this->user->fresh()->telegram_chat_id);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === route('telegram.webhook', $this->church)
            && $request['secret_token'] === self::SECRET);
    }

    public function test_settings_keep_the_old_bot_when_telegram_rejects_the_new_token(): void
    {
        $badToken = '111111111:CCHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw1';
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/bot'.$badToken.'/getMe' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);
        $this->connect($this->user);
        $this->actAsAdmin();

        Livewire::test(TelegramMessagingSettings::class)
            ->fillForm(['bot_token' => $badToken])
            ->call('save')
            ->assertNotified('Telegram did not accept the bot token');

        $manager = app(ChurchModuleManager::class);
        $settings = $manager->settings($this->church, MessagingAvailability::MODULE_TELEGRAM);
        $this->assertSame(self::BOT_TOKEN, $manager->decryptSecret($settings['bot_token']));
        $this->assertSame('555001', $this->user->fresh()->telegram_chat_id);
    }

    protected function connect(User $user, bool $prefer = false): void
    {
        $user->forceFill([
            'telegram_chat_id' => '555001',
            'telegram_username' => 'anna_s',
            ...($prefer ? ['message_preference' => MessagePreference::Telegram] : []),
        ])->save();
    }

    protected function linkToken(?User $user = null): string
    {
        $url = (string) app(TelegramLinks::class)->url($user ?? $this->user);

        return substr($url, strpos($url, '?start=') + strlen('?start='));
    }

    /**
     * @return array<string, mixed>
     */
    protected function startUpdate(string $token, int $chatId = 555001): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'from' => ['id' => $chatId, 'username' => 'anna_s'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'text' => '/start '.$token,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $update
     */
    protected function postWebhook(array $update): mixed
    {
        return $this->postJson(route('telegram.webhook', $this->church), $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    protected function actAsAdmin(): void
    {
        $admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        $this->actingAs($admin);
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
