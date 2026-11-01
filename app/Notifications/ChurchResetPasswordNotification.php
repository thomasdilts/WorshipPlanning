<?php

namespace App\Notifications;

use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Modules\Messaging\Support\ChurchMailer;

/**
 * Filament's password reset email, sent only from the user's church mailbox.
 *
 * When the church has no email settings nothing is sent; the request page still answers the
 * same way, so it never reveals which addresses exist.
 */
class ChurchResetPasswordNotification extends ResetPassword
{
    /**
     * @param  mixed  $notifiable
     * @return list<string>
     */
    public function via($notifiable): array
    {
        return app(ChurchMailer::class)->channelsFor($notifiable->church ?? null);
    }

    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return app(ChurchMailer::class)->applyTo(parent::toMail($notifiable), $notifiable->church ?? null);
    }
}
