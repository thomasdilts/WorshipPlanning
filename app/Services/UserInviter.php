<?php

namespace App\Services;

use App\Enums\PasswordLinkKind;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Messaging\Services\PasswordLinkMessages;
use Modules\Messaging\Support\ChurchMailer;

/**
 * Invite a person to set their own password (admin never invents one).
 *
 * Uses the same signed reset tokens as password recovery; church-scoped via the user record.
 * Sent by the person's preferred messaging when its settings choose a "Set password" template, else by
 * email through the church's SMTP settings — there is no system mailer fallback.
 */
class UserInviter
{
    public function __construct(
        protected ChurchMailer $mailer,
        protected PasswordLinkMessages $passwordLinks,
    ) {}

    public function canInvite(User $user): bool
    {
        return $this->mailer->isConfigured($user->church)
            || $this->passwordLinks->canSendByPreferredChannel($user, PasswordLinkKind::SetPassword);
    }

    public function randomPassword(): string
    {
        return Str::password(32);
    }

    /**
     * @throws ValidationException
     */
    public function send(User $user, ?User $invitedBy = null): void
    {
        if (blank($user->email)) {
            throw ValidationException::withMessages([
                'email' => __('This person needs an email address before you can send an invite.'),
            ]);
        }

        if (! $this->canInvite($user)) {
            throw ValidationException::withMessages([
                'email' => __('Set up the church email settings, or a “Set password” template for their preferred messaging, before sending invites.'),
            ]);
        }

        $key = 'user-invite:'.$user->church_id.':'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => __('An invite was just sent. Try again in :seconds seconds.', [
                    'seconds' => $seconds,
                ]),
            ]);
        }

        $token = Password::broker()->createToken($user);
        $url = Filament::getPanel('admin')->getResetPasswordUrl($token, $user);

        if (! $this->passwordLinks->send($user, PasswordLinkKind::SetPassword, $url, $invitedBy)) {
            if (! $this->mailer->isConfigured($user->church)) {
                throw ValidationException::withMessages([
                    'email' => __('The invite could not be sent by their preferred messaging.'),
                ]);
            }

            $user->notify(new UserInvitationNotification(
                token: $token,
                invitedByName: $invitedBy?->displayName(),
                churchName: $user->church?->name,
            ));
        }

        RateLimiter::hit($key, 60);
    }
}
