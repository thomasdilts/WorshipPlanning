<?php

namespace App\Support;

use App\Enums\MessagePreference;
use App\Models\User;
use Closure;
use Filament\Notifications\Notification;
use Modules\Messaging\Services\PreferenceConfirmation;
use Modules\Messaging\Support\PhoneNumber;

/**
 * Form validation for a changed "preferred messaging": SMS / WhatsApp must deliver a confirmation
 * message or the choice is refused; email is tried too, but a failure only warns the editor.
 */
final class MessagePreferenceRule
{
    /**
     * Session key holding the confirmations already delivered for a change that is not saved yet, so a
     * resubmit after another field's validation error does not send the message again.
     */
    public const CONFIRMED_SESSION_KEY = 'preference-confirmed';

    /**
     * @param  Closure(): User  $recipient  The person as entered in the form (unsaved phone, email, …).
     */
    public static function make(?MessagePreference $current, Closure $recipient, ?int $languageId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($current, $recipient, $languageId): void {
            $chosen = $value instanceof MessagePreference ? $value : MessagePreference::tryFrom((string) $value);

            if ($chosen === null || $chosen === $current) {
                return;
            }

            if ($current === null && $chosen === MessagePreference::Email) {
                return;
            }

            $person = $recipient();
            $alreadyConfirmed = self::CONFIRMED_SESSION_KEY.'.'.sha1(implode('|', [
                $person->getKey(), $chosen->value, PhoneNumber::toMsisdn($person->phone), $person->email,
            ]));

            if (session()->has($alreadyConfirmed)) {
                return;
            }

            $result = app(PreferenceConfirmation::class)->send($person, $chosen, $languageId);

            if ($result->success) {
                session()->put($alreadyConfirmed, true);

                return;
            }

            if ($chosen === MessagePreference::Email) {
                Notification::make()
                    ->warning()
                    ->title(__('The test email could not be sent'))
                    ->body($result->error)
                    ->persistent()
                    ->send();

                return;
            }

            $fail(__('A test message by :channel could not be sent, so this choice was not accepted: :error', [
                'channel' => $chosen->label(),
                'error' => $result->error,
            ]));
        };
    }

    /**
     * Once a preference change is saved, the next change must be confirmed again.
     */
    public static function forgetConfirmations(): void
    {
        session()->forget(self::CONFIRMED_SESSION_KEY);
    }
}
