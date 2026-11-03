<?php

namespace App\Notifications;

use Filament\Facades\Filament;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Messaging\Support\ChurchMailer;

class UserInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $token,
        public ?string $invitedByName = null,
        public ?string $churchName = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return app(ChurchMailer::class)->channelsFor($notifiable->church ?? null);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $church = $this->churchName ?: config('app.name');
        $url = $this->setPasswordUrl($notifiable);

        $mail = (new MailMessage)
            ->subject(__('Set your password for :church', ['church' => $church]))
            ->greeting(__('Welcome to :church', ['church' => $church]))
            ->line(__('You have been invited to WorshipPlanning. Choose your own password to get started.'));

        if (filled($this->invitedByName)) {
            $mail->line(__('Invited by :name.', ['name' => $this->invitedByName]));
        }

        $mail
            ->action(__('Set password'), $url)
            ->line(__('This link expires in :count minutes.', [
                'count' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
            ]))
            ->line(__('If you did not expect this invitation, you can ignore this email.'));

        return app(ChurchMailer::class)->applyTo($mail, $notifiable->church ?? null);
    }

    protected function setPasswordUrl(object $notifiable): string
    {
        if (! $notifiable instanceof CanResetPassword) {
            return url('/');
        }

        $panel = Filament::getPanel('admin');

        return $panel->getResetPasswordUrl($this->token, $notifiable);
    }
}
