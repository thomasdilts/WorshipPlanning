<?php

namespace Tests\Feature\Messaging;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\MessagePreference;
use App\Enums\PasswordLinkKind;
use App\Models\Church;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\ChurchResetPasswordNotification;
use App\Notifications\UserInvitationNotification;
use App\Services\UserInviter;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Messaging\Mail\GenericMessageMail;
use Modules\Messaging\Support\MessagingAvailability;
use Tests\TestCase;

class PasswordLinkMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_goes_by_sms_from_the_chosen_template_without_email_settings_and_the_link_is_not_logged(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.ip1sms.com/v2/batches' => Http::response(['id' => 'batch-1'], 200)]);
        Notification::fake();
        $church = Church::factory()->create(['name' => 'Kyrkan']);
        $template = $this->template($church, 'Hej {{first_name}}, välj lösenord: {{password_url}} ({{expire_minutes}} min)');
        $this->enableSms($church, [PasswordLinkKind::SetPassword->templateSettingKey() => $template->id]);
        $user = $this->member($church, MessagePreference::Sms);

        app(UserInviter::class)->send($user);

        Http::assertSent(fn ($request): bool => str_starts_with($request['body'], 'Hej Anna, välj lösenord: http')
            && str_contains($request['body'], 'password-reset')
            && str_ends_with($request['body'], '(60 min)'));
        Notification::assertNothingSent();
        $logged = OutboundMessage::query()->sole();
        $this->assertSame('set_password', $logged->meta['kind']);
        $this->assertStringNotContainsString('password-reset', $logged->body);
    }

    public function test_invite_is_refused_when_the_sms_fails_and_there_is_no_email_to_fall_back_to(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.ip1sms.com/v2/batches' => Http::response('Down', 500)]);
        Notification::fake();
        $church = Church::factory()->create();
        $this->enableSms($church, [PasswordLinkKind::SetPassword->templateSettingKey() => $this->template($church, '{{password_url}}')->id]);
        $user = $this->member($church, MessagePreference::Sms);

        $this->expectException(ValidationException::class);

        app(UserInviter::class)->send($user);
    }

    public function test_reset_request_goes_by_the_whatsapp_template_with_the_link_in_its_button(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v21.0/111222333/message_templates*' => Http::response(['data' => [[
                'name' => 'aterstall_losenord',
                'language' => 'sv',
                'status' => 'APPROVED',
                'components' => [
                    ['type' => 'BODY', 'text' => 'Hej {{first_name}}! Länken går ut om {{expire_minutes}} minuter.'],
                    ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Återställ lösenord', 'url' => 'https://jesusislord.se/admin/password-reset/{{1}}']]],
                ],
            ]]]),
            'graph.facebook.com/v21.0/123456789/messages' => Http::response(['messages' => [['id' => 'wamid.1']]]),
        ]);
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $church = Church::factory()->create();
        $manager = app(ChurchModuleManager::class);
        $manager->enable($church, MessagingAvailability::MODULE_WHATSAPP, [
            'api_version' => 'v21.0',
            'access_token' => $manager->encryptSecret('wa-token'),
            'phone_number_id' => '123456789',
            'business_account_id' => '111222333',
            PasswordLinkKind::ResetPassword->whatsAppSettingKey() => 'aterstall_losenord',
        ]);
        $user = $this->member($church, MessagePreference::WhatsApp);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $user->email])
            ->call('request')
            ->assertNotified();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/messages')
            && $request['template']['name'] === 'aterstall_losenord'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Anna'
            && $request['template']['components'][1]['sub_type'] === 'url'
            && str_starts_with($request['template']['components'][1]['parameters'][0]['text'], 'reset?'));
        Notification::assertNotSentTo($user, ChurchResetPasswordNotification::class);
    }

    public function test_reset_falls_back_to_the_email_template_when_the_preferred_sms_has_none(): void
    {
        Mail::fake();
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $church = Church::factory()->withEmailSettings()->create();
        $this->enableSms($church);
        $template = $this->template($church, 'Återställ: {{password_url}}');
        $manager = app(ChurchModuleManager::class);
        $manager->updateSettings($church, MessagingAvailability::MODULE_EMAIL, [
            ...$manager->settings($church, MessagingAvailability::MODULE_EMAIL),
            PasswordLinkKind::ResetPassword->templateSettingKey() => $template->id,
        ]);
        $user = $this->member($church, MessagePreference::Sms);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $user->email])
            ->call('request')
            ->assertNotified();

        Mail::assertSent(GenericMessageMail::class, fn (GenericMessageMail $mail): bool => $mail->hasTo($user->email));
        Notification::assertNotSentTo($user, ChurchResetPasswordNotification::class);
    }

    public function test_invite_sends_the_built_in_email_when_no_template_is_chosen(): void
    {
        Notification::fake();
        $church = Church::factory()->withEmailSettings()->create();
        $user = $this->member($church, MessagePreference::Email);

        app(UserInviter::class)->send($user);

        Notification::assertSentTo($user, UserInvitationNotification::class);
    }

    protected function template(Church $church, string $textBody): MessageTemplate
    {
        return MessageTemplate::query()->create([
            'church_id' => $church->id,
            'name' => 'Lösenord',
            'purpose' => 'other',
            'text_body' => $textBody,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function enableSms(Church $church, array $settings = []): void
    {
        $manager = app(ChurchModuleManager::class);
        $manager->enable($church, MessagingAvailability::MODULE_SMS, [
            'api_base' => 'https://api.ip1sms.com/v2',
            'api_token' => $manager->encryptSecret('test-token'),
            'sender' => 'Worship',
            ...$settings,
        ]);
    }

    protected function member(Church $church, MessagePreference $preference): User
    {
        return User::factory()->create([
            'church_id' => $church->id,
            'display_name' => 'Anna Svensson',
            'phone' => '070-123 45 67',
            'message_preference' => $preference,
        ])->loadMissing('church');
    }
}
