<?php

namespace App\Filament\Forms;

use App\Enums\MessagePreference;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Modules\Messaging\Support\TelegramLinks;

/**
 * Telegram connection status under "preferred messaging". A bot can only message people who pressed
 * Start in a chat with it, so the person taps "Connect Telegram" (a one-time deep link) on their own
 * profile; the webhook then saves their chat. Admins only see whether the person is connected.
 */
class TelegramConnectField
{
    /**
     * @param  Closure  $person  Returns the saved person (or null for a new one); evaluated with the component's injections.
     * @param  bool  $canConnect  Whether the viewer is the person themself (profile page).
     * @return array{0: Callout, 1: Callout}
     */
    public static function components(Closure $person, bool $canConnect): array
    {
        return [
            Callout::make(__('Connect Telegram'))
                ->warning()
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->description($canConnect
                    ? __('Tap “Connect Telegram” and press Start in the chat that opens. Then come back here and save.')
                    : __('Telegram is not connected. Only the person can connect it: they choose Telegram in their own profile and tap “Connect Telegram”.'))
                ->actions($canConnect ? self::connectActions($person, 'connectTelegram', __('Connect Telegram'), withRefresh: true) : [])
                ->visible(fn (Callout $component, Get $get): bool => self::prefersTelegram($get('message_preference'))
                    && self::connection($component->evaluate($person)) === null),
            Callout::make(fn (Callout $component): string => self::connectedTitle($component->evaluate($person)))
                ->success()
                ->icon(Heroicon::OutlinedCheckCircle)
                ->description($canConnect ? __('To use another Telegram account, connect again from that account.') : null)
                ->actions($canConnect ? self::connectActions($person, 'reconnectTelegram', __('Connect again'), withRefresh: false) : [])
                ->visible(fn (Callout $component, Get $get): bool => self::prefersTelegram($get('message_preference'))
                    && self::connection($component->evaluate($person)) !== null),
        ];
    }

    /**
     * The person's saved Telegram chat, read fresh because the webhook saves it while the form is open.
     *
     * @return array{chat_id: string, username: ?string}|null
     */
    public static function connection(?User $person): ?array
    {
        if ($person?->getKey() === null) {
            return null;
        }

        $row = User::query()->whereKey($person->getKey())->first(['id', 'telegram_chat_id', 'telegram_username']);

        if (blank($row?->telegram_chat_id)) {
            return null;
        }

        return ['chat_id' => (string) $row->telegram_chat_id, 'username' => $row->telegram_username];
    }

    /**
     * @return list<Action>
     */
    protected static function connectActions(Closure $person, string $name, string $label, bool $withRefresh): array
    {
        $actions = [
            Action::make($name)
                ->label($label)
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (Action $action): ?string => ($user = $action->evaluate($person)) instanceof User
                    ? app(TelegramLinks::class)->url($user)
                    : null, shouldOpenInNewTab: true)
                ->visible(fn (Action $action): bool => ($user = $action->evaluate($person)) instanceof User
                    && app(TelegramLinks::class)->url($user) !== null),
        ];

        if ($withRefresh) {
            $actions[] = Action::make('refreshTelegram')
                ->label(__('I have pressed Start'))
                ->color('gray')
                ->action(fn (): null => null);
        }

        return $actions;
    }

    protected static function connectedTitle(?User $person): string
    {
        $username = self::connection($person)['username'] ?? null;

        return filled($username)
            ? __('Telegram is connected (@:username)', ['username' => $username])
            : __('Telegram is connected');
    }

    protected static function prefersTelegram(mixed $preference): bool
    {
        $preference = $preference instanceof MessagePreference ? $preference : MessagePreference::tryFrom((string) $preference);

        return $preference === MessagePreference::Telegram;
    }
}
