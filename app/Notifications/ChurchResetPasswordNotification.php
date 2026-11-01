<?php

namespace App\Notifications;

use App\Enums\PasswordLinkKind;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Modules\Messaging\Services\PasswordLinkMessages;
use Modules\Messaging\Support\ChurchMailer;

/**
 * Filament's password reset link: sent from the "Reset password" template chosen for the person's
 * preferred messaging (or email), else as the built-in email from the user's church mailbox.
 *
 * When nothing can be sent the request page still answers the same way, so it never reveals which
 * addresses exist.
 */
class ChurchResetPasswordNotification extends ResetPassword
{
    /**
     * Sends the template message right away; the built-in email only goes out when no template could.
     *
     * @param  mixed  $notifiable
     * @return list<string>
     */
    public function via($notifiable): array
    {
        if ($notifiable instanceof User
            && isset($this->url)
            && app(PasswordLinkMessages::class)->send($notifiable, PasswordLinkKind::ResetPassword, $this->url)) {
            return [];
        }

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
