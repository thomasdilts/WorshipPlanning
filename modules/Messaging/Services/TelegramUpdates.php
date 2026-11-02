<?php

namespace Modules\Messaging\Services;

use App\Models\Church;
use App\Models\User;
use Modules\Messaging\Support\TelegramBotApi;
use Modules\Messaging\Support\TelegramLinks;

/**
 * Handles updates Telegram posts to a church's webhook: "/start <token>" from a Connect Telegram link
 * connects that person, and blocking the bot disconnects everyone using that chat.
 *
 * @see https://core.telegram.org/bots/api#update
 */
class TelegramUpdates
{
    public function __construct(
        protected TelegramBotApi $api,
        protected TelegramLinks $links,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(Church $church, array $update): void
    {
        if (isset($update['my_chat_member'])) {
            $this->handleMembership($church, (array) $update['my_chat_member']);

            return;
        }

        if (isset($update['message'])) {
            $this->handleMessage($church, (array) $update['message']);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function handleMessage(Church $church, array $message): void
    {
        if (data_get($message, 'chat.type') !== 'private' || data_get($message, 'chat.id') === null) {
            return;
        }

        $chatId = (string) data_get($message, 'chat.id');
        $text = trim((string) data_get($message, 'text', ''));

        if (! preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?$/', $text, $match)) {
            $this->reply($church, $chatId, __('This bot sends messages from :church. Answer invitations with the buttons or links in each message.', ['church' => $church->name]));

            return;
        }

        $token = $match[1] ?? null;
        $user = $token !== null ? $this->links->consume($church, $token) : null;

        if ($user === null) {
            $this->reply($church, $chatId, $token === null
                ? __('To get messages from :church here, choose Telegram as preferred messaging in your profile and tap “Connect Telegram”.', ['church' => $church->name])
                : __('This link has expired. Tap “Connect Telegram” in your profile again.'));

            return;
        }

        $username = data_get($message, 'from.username');

        $user->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_username' => filled($username) ? mb_substr((string) $username, 0, 64) : null,
        ])->save();

        $locale = $user->language?->iso;

        $this->reply($church, $chatId, __('Connected! You will get messages from :church here. Go back to your profile and save it to finish.', ['church' => $church->name], $locale));
    }

    /**
     * @param  array<string, mixed>  $membership
     */
    protected function handleMembership(Church $church, array $membership): void
    {
        $status = data_get($membership, 'new_chat_member.status');

        if (data_get($membership, 'chat.type') !== 'private' || ! in_array($status, ['kicked', 'left'], true)) {
            return;
        }

        User::query()
            ->where('church_id', $church->getKey())
            ->where('telegram_chat_id', (string) data_get($membership, 'chat.id'))
            ->update(['telegram_chat_id' => null, 'telegram_username' => null]);
    }

    protected function reply(Church $church, string $chatId, string $text): void
    {
        try {
            $this->api->callForChurch($church, 'sendMessage', ['chat_id' => $chatId, 'text' => $text]);
        } catch (\Throwable $e) {
            report(new \RuntimeException(TelegramBotApi::redact($e->getMessage(), $this->api->botToken($church))));
        }
    }
}
