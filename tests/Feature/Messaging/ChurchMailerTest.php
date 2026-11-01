<?php

namespace Tests\Feature\Messaging;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\MessagePreference;
use App\Models\Church;
use App\Models\User;
use App\Notifications\ChurchResetPasswordNotification;
use App\Notifications\UserInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Modules\Messaging\Data\OutboundMessageData;
use Modules\Messaging\Mail\GenericMessageMail;
use Modules\Messaging\Services\MessageSender;
use Modules\Messaging\Support\ChurchMailer;
use Modules\Messaging\Support\MessagingAvailability;
use Tests\TestCase;

class ChurchMailerTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_and_invite_emails_use_the_church_mailbox(): void
    {
        $church = Church::factory()->withEmailSettings([
            'host' => 'mx.church.test',
            'from_address' => 'noreply@church.test',
            'from_name' => 'Church Office',
        ])->create();
        $user = User::factory()->create(['church_id' => $church->id]);

        foreach ([$this->resetNotification(), new UserInvitationNotification('token')] as $notification) {
            $mail = $notification->toMail($user);

            $this->assertSame(['mail'], $notification->via($user));
            $this->assertSame('church_smtp_'.$church->id, $mail->mailer);
            $this->assertSame(['noreply@church.test', 'Church Office'], $mail->from);
        }

        $this->assertSame('mx.church.test', config('mail.mailers.church_smtp_'.$church->id.'.host'));
    }

    public function test_reset_and_invite_emails_are_not_sent_without_church_email_settings(): void
    {
        $emptySettingsChurch = Church::factory()->create();
        app(ChurchModuleManager::class)->enable($emptySettingsChurch, MessagingAvailability::MODULE_EMAIL);

        $disabledModuleChurch = Church::factory()->withEmailSettings()->create();
        app(ChurchModuleManager::class)->disable($disabledModuleChurch, MessagingAvailability::MODULE_EMAIL);

        $users = [
            User::factory()->create(['church_id' => $emptySettingsChurch->id]),
            User::factory()->create(['church_id' => $disabledModuleChurch->id]),
            User::factory()->create(['church_id' => Church::factory()->create()->id]),
            User::factory()->create(['church_id' => null]),
        ];

        foreach ($users as $user) {
            $this->assertSame([], $this->resetNotification()->via($user));
            $this->assertSame([], (new UserInvitationNotification('token'))->via($user));
        }
    }

    public function test_church_message_does_not_change_the_system_sender(): void
    {
        Mail::fake();
        config(['mail.from' => ['address' => 'system@planner.test', 'name' => 'Planner']]);

        $church = Church::factory()->withEmailSettings(['from_address' => 'noreply@church.test', 'from_name' => 'Church Office'])->create();
        $member = User::factory()->create([
            'church_id' => $church->id,
            'message_preference' => MessagePreference::Email,
        ]);

        $result = app(MessageSender::class)->sendToUser($member, new OutboundMessageData(subject: 'Subj', body: 'Body'));

        $this->assertTrue($result->success);
        Mail::assertSent(
            GenericMessageMail::class,
            fn (GenericMessageMail $mail): bool => $mail->from == [['address' => 'noreply@church.test', 'name' => 'Church Office']],
        );
        $this->assertSame('system@planner.test', config('mail.from.address'));
    }

    public function test_church_mailer_is_rebuilt_after_settings_change(): void
    {
        Mail::swap(new MailManager($this->app));

        $church = Church::factory()->withEmailSettings(['host' => 'old.church.test'])->create();
        $churchMailer = app(ChurchMailer::class);

        $this->assertSame('smtp://old.church.test:587', (string) Mail::mailer($churchMailer->mailerFor($church))->getSymfonyTransport());

        app(ChurchModuleManager::class)->updateSettings($church, MessagingAvailability::MODULE_EMAIL, [
            'email' => ['host' => 'new.church.test', 'port' => 465, 'encryption' => 'ssl', 'from_address' => 'noreply@church.test'],
        ]);

        $this->assertSame('smtps://new.church.test', (string) Mail::mailer($churchMailer->mailerFor($church->fresh()))->getSymfonyTransport());
    }

    protected function resetNotification(): ChurchResetPasswordNotification
    {
        $notification = new ChurchResetPasswordNotification('token');
        $notification->url = 'https://planner.test/reset';

        return $notification;
    }
}
