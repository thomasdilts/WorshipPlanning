<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Messaging\Support\ChurchMailer;

/**
 * Invite a person to set their own password (admin never invents one).
 *
 * Uses the same signed reset tokens as password recovery; church-scoped via the user record.
 * Only possible when the church's email module has SMTP settings — there is no system mailer fallback.
 */
class UserInviter
{
    public function canInvite(User $user): bool
    {
        return app(ChurchMailer::class)->isConfigured($user->church);
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
                'email' => __('Set up the church email settings before sending invites.'),
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

        $user->notify(new UserInvitationNotification(
            token: $token,
            invitedByName: $invitedBy?->displayName(),
            churchName: $user->church?->name,
        ));

        RateLimiter::hit($key, 60);
    }
}
