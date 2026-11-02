<?php

namespace Modules\Messaging\Channels;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use App\Models\User;
use Modules\Messaging\Contracts\MessageChannel;
use Modules\Messaging\Data\OutboundMessageData;
use Modules\Messaging\Data\SendResult;
use Modules\Messaging\Support\MessagingAvailability;
use Modules\Messaging\Support\TelegramBotApi;

/**
 * Telegram messages from the church's own bot to each person's private chat with it. Sends the same text
 * version as SMS, with the subject in bold and, for invitations, Yes / No buttons.
 *
 * @see https://core.telegram.org/bots/api#sendmessage
 */
class TelegramChannel implements MessageChannel
{
    public const MAX_MESSAGE_LENGTH = 4096;

    public function __construct(
        protected ChurchModuleManager $modules,
        protected TelegramBotApi $api,
    ) {}

    public function key(): string
    {
        return 'telegram';
    }

    public function label(): string
    {
        return __('Telegram');
    }

    public function isConfigured(Church $church): bool
    {
        if (! $this->modules->isEnabled($church, MessagingAvailability::MODULE_TELEGRAM)) {
            return false;
        }

        $settings = $this->modules->settings($church, MessagingAvailability::MODULE_TELEGRAM);

        return filled($settings['bot_token'] ?? null) && filled($settings['bot_username'] ?? null);
    }

    public function send(User $recipient, OutboundMessageData $message): SendResult
    {
        $church = $recipient->church;

        if ($church === null) {
            return SendResult::fail($this->key(), 'Recipient has no church.');
        }

        if (! $this->isConfigured($church)) {
            return SendResult::fail($this->key(), 'Telegram is not configured for this church.');
        }

        $chatId = trim((string) $recipient->telegram_chat_id);

        if ($chatId === '') {
            return SendResult::fail($this->key(), __('This person has not connected Telegram yet.'));
        }

        $body = $message->plainText();

        if ($body === '') {
            return SendResult::fail($this->key(), 'Message body is empty.');
        }

        $text = filled($message->subject)
            ? '<b>'.self::escape(trim($message->subject)).'</b>'."\n\n".self::escape($body)
            : self::escape($body);

        if (mb_strlen($text) > self::MAX_MESSAGE_LENGTH) {
            return SendResult::fail($this->key(), 'Telegram message exceeds '.self::MAX_MESSAGE_LENGTH.' characters.');
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];

        $buttons = $this->answerButtons($message);

        if ($buttons !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => [$buttons]];
        }

        $token = $this->api->botToken($church);

        try {
            $response = $this->api->call((string) $token, 'sendMessage', $payload);

            if ($response->status() === 403) {
                $this->disconnect($recipient);

                return SendResult::fail($this->key(), __('This person has blocked the Telegram bot and must connect Telegram again.'));
            }

            if (! $response->successful() || $response->json('ok') !== true) {
                return SendResult::fail($this->key(), TelegramBotApi::errorFrom($response));
            }

            return SendResult::ok($this->key(), (string) $response->json('result.message_id'));
        } catch (\Throwable $e) {
            return SendResult::fail($this->key(), TelegramBotApi::redact($e->getMessage(), $token));
        }
    }

    /**
     * Yes / No buttons opening the quick answer links. Telegram only accepts public URLs, so they are
     * left out when the app does not run on https (local development).
     *
     * @return list<array{text: string, url: string}>|null
     */
    protected function answerButtons(OutboundMessageData $message): ?array
    {
        $accept = (string) ($message->placeholders['accept_url'] ?? '');
        $decline = (string) ($message->placeholders['decline_url'] ?? '');

        if (! str_starts_with($accept, 'https://') || ! str_starts_with($decline, 'https://')) {
            return null;
        }

        return [
            ['text' => '✅ '.__('Yes, I can help'), 'url' => $accept],
            ['text' => '❌ '.__("No, I can't make it"), 'url' => $decline],
        ];
    }

    protected function disconnect(User $recipient): void
    {
        if ($recipient->getKey() === null) {
            return;
        }

        User::query()->whereKey($recipient->getKey())->update(['telegram_chat_id' => null, 'telegram_username' => null]);
        $recipient->forceFill(['telegram_chat_id' => null, 'telegram_username' => null]);
    }

    /**
     * Telegram's HTML mode only needs &, < and > escaped.
     */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
