<?php

namespace Tests\Feature\Messaging;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\MessagePreference;
use App\Enums\MessageTemplatePurpose;
use App\Filament\Pages\EditProfile;
use App\Filament\Pages\SmsMessagingSettings;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Church;
use App\Models\Language;
use App\Models\MessageTemplate;
use App\Models\User;
use Database\Seeders\LanguagesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Modules\Messaging\Mail\GenericMessageMail;
use Modules\Messaging\Support\MessagingAvailability;
use Tests\TestCase;

class PreferenceConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed([RolesAndPermissionsSeeder::class, LanguagesSeeder::class]);

        $confirmation = MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'name' => 'SMS confirmation',
            'text_body' => 'Hej {{first_name}}, SMS fungerar! /{{message_sender}}',
            'is_active' => true,
        ]);

        $manager = app(ChurchModuleManager::class);
        $manager->enable($this->church, MessagingAvailability::MODULE_EMAIL);
        $manager->enable($this->church, MessagingAvailability::MODULE_SMS, [
            'api_base' => 'https://api.ip1sms.com/v2',
            'api_token' => $manager->encryptSecret('test-token'),
            'sender' => 'Worship',
            'confirmation_template_id' => $confirmation->id,
        ]);

        $this->user = User::factory()->create([
            'church_id' => $this->church->id,
            'display_name' => 'Anna Svensson',
            'phone' => '070-123 45 67',
            'message_preference' => MessagePreference::Email,
            'language_id' => Language::query()->where('iso', 'en')->value('id'),
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->user, 'Member');

        app(CurrentChurch::class)->set($this->church);
        $this->actingAs($this->user);
    }

    public function test_switching_to_sms_sends_the_confirmation_template_chosen_in_the_sms_settings(): void
    {
        Http::fake(['api.ip1sms.com/*' => Http::response(['id' => 'batch-1'], 200)]);

        $this->saveProfile(['message_preference' => MessagePreference::Sms->value])->assertHasNoFormErrors();

        $this->assertSame(MessagePreference::Sms, $this->user->fresh()->message_preference);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['recipients'] === ['46701234567']
            && $request['body'] === 'Hej Anna, SMS fungerar! /Anna Svensson');
    }

    public function test_switching_back_to_a_channel_later_in_the_same_session_confirms_it_again(): void
    {
        Http::fake(['api.ip1sms.com/*' => Http::response(['id' => 'batch-1'], 200)]);
        Mail::fake();

        $this->saveProfile(['message_preference' => MessagePreference::Sms->value])->assertHasNoFormErrors();
        $this->saveProfile(['message_preference' => MessagePreference::Email->value])->assertHasNoFormErrors();
        $this->saveProfile(['message_preference' => MessagePreference::Sms->value])->assertHasNoFormErrors();

        $this->assertSame(MessagePreference::Sms, $this->user->fresh()->message_preference);
        Http::assertSentCount(2);
    }

    public function test_sms_choice_is_refused_when_no_confirmation_template_is_chosen(): void
    {
        Http::fake();
        $manager = app(ChurchModuleManager::class);
        $manager->updateSettings($this->church, MessagingAvailability::MODULE_SMS, [
            ...$manager->settings($this->church, MessagingAvailability::MODULE_SMS),
            'confirmation_template_id' => null,
        ]);

        $this->saveProfile(['message_preference' => MessagePreference::Sms->value])
            ->assertHasFormErrors(['message_preference']);

        $this->assertSame(MessagePreference::Email, $this->user->fresh()->message_preference);
        Http::assertNothingSent();
    }

    public function test_switching_to_whatsapp_sends_the_meta_template_chosen_in_the_whatsapp_settings_in_the_chosen_language(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v21.0/111222333/message_templates*' => Http::response(['data' => [
                ['name' => 'confirm', 'language' => 'en', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hi {{first_name}}']]],
                ['name' => 'confirm', 'language' => 'sv', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hej {{first_name}}']]],
            ]]),
            'graph.facebook.com/v21.0/123456789/messages' => Http::response(['messages' => [['id' => 'wamid.1']]]),
        ]);
        $manager = app(ChurchModuleManager::class);
        $manager->enable($this->church, MessagingAvailability::MODULE_WHATSAPP, [
            'api_version' => 'v21.0',
            'access_token' => $manager->encryptSecret('wa-token'),
            'phone_number_id' => '123456789',
            'business_account_id' => '111222333',
            'confirmation_whatsapp_template' => 'confirm',
        ]);

        $this->saveProfile([
            'message_preference' => MessagePreference::WhatsApp->value,
            'language_id' => Language::query()->where('iso', 'sv')->value('id'),
        ])->assertHasNoFormErrors();

        $this->assertSame(MessagePreference::WhatsApp, $this->user->fresh()->message_preference);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/messages')
            && $request['template']['name'] === 'confirm'
            && $request['template']['language']['code'] === 'sv'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Anna');
    }

    public function test_whatsapp_choice_is_refused_when_no_confirmation_template_is_chosen(): void
    {
        Http::fake();
        $manager = app(ChurchModuleManager::class);
        $manager->enable($this->church, MessagingAvailability::MODULE_WHATSAPP, [
            'api_version' => 'v21.0',
            'access_token' => $manager->encryptSecret('wa-token'),
            'phone_number_id' => '123456789',
            'business_account_id' => '111222333',
        ]);

        $this->saveProfile(['message_preference' => MessagePreference::WhatsApp->value])
            ->assertHasFormErrors(['message_preference']);

        $this->assertSame(MessagePreference::Email, $this->user->fresh()->message_preference);
        Http::assertNothingSent();
    }

    public function test_sms_choice_is_refused_when_the_confirmation_fails(): void
    {
        Http::fake(['api.ip1sms.com/*' => Http::response(['Sender' => ['Invalid sender']], 400)]);

        $this->saveProfile(['message_preference' => MessagePreference::Sms->value])
            ->assertHasFormErrors(['message_preference']);

        $this->assertSame(MessagePreference::Email, $this->user->fresh()->message_preference);
    }

    public function test_sms_choice_is_refused_without_a_usable_mobile_number(): void
    {
        Http::fake();

        $this->saveProfile(['message_preference' => MessagePreference::Sms->value, 'phone' => '08-123 456 78'])
            ->assertHasFormErrors(['message_preference']);

        $this->assertSame(MessagePreference::Email, $this->user->fresh()->message_preference);
        Http::assertNothingSent();
    }

    public function test_email_choice_is_saved_even_when_the_test_email_fails_but_the_editor_is_warned(): void
    {
        $this->user->update(['message_preference' => MessagePreference::Sms]);

        $this->saveProfile(['message_preference' => MessagePreference::Email->value])
            ->assertHasNoFormErrors()
            ->assertNotified(__('The test email could not be sent'));

        $this->assertSame(MessagePreference::Email, $this->user->fresh()->message_preference);
    }

    public function test_email_choice_sends_the_confirmation_template_chosen_in_the_email_settings(): void
    {
        Mail::fake();
        $template = MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'name' => 'Email confirmation',
            'purpose' => MessageTemplatePurpose::Other,
            'subject' => 'Changed by {{message_sender}}',
            'body' => '<p>Hej {{first_name}}, e-post fungerar!</p>',
        ]);
        app(ChurchModuleManager::class)->updateSettings($this->church, MessagingAvailability::MODULE_EMAIL, [
            'email' => ['host' => 'smtp.test', 'from_address' => 'church@test'],
            'confirmation_template_id' => $template->id,
        ]);
        $this->user->update(['message_preference' => MessagePreference::Sms]);

        $this->saveProfile(['message_preference' => MessagePreference::Email->value])
            ->assertHasNoFormErrors();

        Mail::assertSent(GenericMessageMail::class, fn (GenericMessageMail $mail): bool => $mail->messageSubject === 'Changed by Anna Svensson'
            && str_contains($mail->messageBody, 'Hej Anna, e-post fungerar!'));
    }

    public function test_email_choice_is_saved_but_the_editor_is_warned_when_no_confirmation_template_is_chosen(): void
    {
        Mail::fake();
        app(ChurchModuleManager::class)->updateSettings($this->church, MessagingAvailability::MODULE_EMAIL, [
            'email' => ['host' => 'smtp.test', 'from_address' => 'church@test'],
        ]);
        $this->user->update(['message_preference' => MessagePreference::Sms]);

        $this->saveProfile(['message_preference' => MessagePreference::Email->value])
            ->assertHasNoFormErrors()
            ->assertNotified(__('The test email could not be sent'));

        $this->assertSame(MessagePreference::Email, $this->user->fresh()->message_preference);
        Mail::assertNothingSent();
    }

    public function test_resend_test_message_sends_the_confirmation_again_to_the_details_in_the_form(): void
    {
        Http::fake(['api.ip1sms.com/*' => Http::response(['id' => 'batch-1'], 200)]);
        $this->user->update(['message_preference' => MessagePreference::Sms]);

        Livewire::test(EditProfile::class)
            ->fillForm(['phone' => '070-999 88 77'])
            ->callAction(TestAction::make('resendPreferenceTestMessage')->schemaComponent('preferenceTestMessage'))
            ->assertNotified('Test message sent by SMS');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['recipients'] === ['46709998877']);
        $this->assertSame('070-123 45 67', $this->user->fresh()->phone);
    }

    public function test_resend_test_message_after_choosing_a_new_channel_is_not_sent_again_on_save(): void
    {
        Http::fake(['api.ip1sms.com/*' => Http::response(['id' => 'batch-1'], 200)]);

        Livewire::test(EditProfile::class)
            ->fillForm(['message_preference' => MessagePreference::Sms->value])
            ->callAction(TestAction::make('resendPreferenceTestMessage')->schemaComponent('preferenceTestMessage'))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(MessagePreference::Sms, $this->user->fresh()->message_preference);
        Http::assertSentCount(1);
    }

    public function test_resend_test_message_reports_why_it_failed(): void
    {
        Http::fake(['api.ip1sms.com/*' => Http::response(['Sender' => ['Invalid sender']], 400)]);
        $this->user->update(['message_preference' => MessagePreference::Sms]);

        Livewire::test(EditProfile::class)
            ->callAction(TestAction::make('resendPreferenceTestMessage')->schemaComponent('preferenceTestMessage'))
            ->assertNotified('The test message by SMS could not be sent');
    }

    public function test_resend_test_message_is_limited_per_minute(): void
    {
        Http::fake(['api.ip1sms.com/*' => Http::response(['id' => 'batch-1'], 200)]);
        $this->user->update(['message_preference' => MessagePreference::Sms]);
        RateLimiter::clear('preference-test:'.$this->user->id);

        $component = Livewire::test(EditProfile::class);

        foreach (range(1, 4) as $attempt) {
            $component->callAction(TestAction::make('resendPreferenceTestMessage')->schemaComponent('preferenceTestMessage'));
        }

        Http::assertSentCount(3);
    }

    public function test_saving_without_changing_the_preference_sends_nothing(): void
    {
        Http::fake();
        Mail::fake();

        $this->saveProfile(['display_name' => 'New name'])->assertHasNoFormErrors();

        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_admin_cannot_switch_someone_to_sms_when_the_confirmation_fails(): void
    {
        Http::fake(['api.ip1sms.com/*' => Http::response(['Sender' => ['Invalid sender']], 400)]);

        $admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $this->user->getRouteKey()])
            ->fillForm(['message_preference' => MessagePreference::Sms->value])
            ->call('save')
            ->assertHasFormErrors(['message_preference']);

        $this->assertSame(MessagePreference::Email, $this->user->fresh()->message_preference);
        Http::assertSent(fn ($request): bool => $request['recipients'] === ['46701234567']);
    }

    public function test_sms_settings_save_the_confirmation_template(): void
    {
        $template = MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'name' => 'Another confirmation',
            'purpose' => MessageTemplatePurpose::Other,
            'text_body' => 'SMS works',
            'is_active' => true,
        ]);
        $admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        $this->actingAs($admin);

        Livewire::test(SmsMessagingSettings::class)
            ->fillForm(['confirmation_template_id' => $template->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            $template->id,
            app(ChurchModuleManager::class)->settings($this->church, MessagingAvailability::MODULE_SMS)['confirmation_template_id'],
        );
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
                'phone' => $this->user->phone,
                'message_preference' => $this->user->message_preference->value,
                ...$changes,
            ])
            ->call('save');
    }
}
