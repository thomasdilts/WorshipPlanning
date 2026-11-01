<?php

namespace App\Filament\Forms;

use App\Enums\MessagePreference;
use App\Models\User;
use App\Support\MessagePreferenceRule;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Messaging\Services\PreferenceConfirmation;

/**
 * "Resend test message" under the preferred messaging select: sends the confirmation again by the chosen
 * channel, to the details as entered in the form, so people can check that messages arrive.
 */
class PreferenceTestMessageAction
{
    public const MAX_PER_MINUTE = 3;

    /**
     * @param  Closure  $recipient  Returns the person as entered in the form; evaluated with the action's injections (Get, record, …).
     * @param  Closure(): bool  $visible
     */
    public static function make(Closure $recipient, Closure $visible): Actions
    {
        return Actions::make([
            Action::make('resendPreferenceTestMessage')
                ->label(__('Resend test message'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->action(function (Action $action, Get $get) use ($recipient): void {
                    self::send($action->evaluate($recipient), $get('message_preference'), $get('language_id'));
                }),
        ])->key('preferenceTestMessage')->visible(fn (Get $get): bool => $visible() && self::preference($get('message_preference')) !== null);
    }

    protected static function send(User $person, mixed $preference, mixed $languageId): void
    {
        $preference = self::preference($preference);

        if ($preference === null) {
            return;
        }

        $key = 'preference-test:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_MINUTE)) {
            Notification::make()
                ->warning()
                ->title(__('Too many test messages. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]))
                ->send();

            return;
        }

        RateLimiter::hit($key, 60);

        $result = app(PreferenceConfirmation::class)->send($person, $preference, filled($languageId) ? (int) $languageId : null);

        if (! $result->success) {
            Notification::make()
                ->danger()
                ->title(__('The test message by :channel could not be sent', ['channel' => $preference->label()]))
                ->body($result->error)
                ->persistent()
                ->send();

            return;
        }

        session()->put(MessagePreferenceRule::confirmationKey($person, $preference), true);

        Notification::make()
            ->success()
            ->title(__('Test message sent by :channel', ['channel' => $preference->label()]))
            ->send();
    }

    protected static function preference(mixed $preference): ?MessagePreference
    {
        return $preference instanceof MessagePreference ? $preference : MessagePreference::tryFrom((string) $preference);
    }
}
