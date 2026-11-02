<?php

namespace Modules\Messaging\Support;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Telegram Bot API. The bot token is part of every URL, so errors are passed
 * through {@see self::redact()} before they are shown or stored.
 *
 * @see https://core.telegram.org/bots/api
 */
class TelegramBotApi
{
    public const BASE_URL = 'https://api.telegram.org';

    public function __construct(
        protected ChurchModuleManager $modules,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function call(string $botToken, string $method, array $params = []): Response
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->post(self::BASE_URL.'/bot'.$botToken.'/'.$method, $params);
    }

    /**
     * Calls the API with the church's saved bot token.
     *
     * @param  array<string, mixed>  $params
     */
    public function callForChurch(Church $church, string $method, array $params = []): Response
    {
        return $this->call((string) $this->botToken($church), $method, $params);
    }

    public function botToken(Church $church): ?string
    {
        $settings = $this->modules->settings($church, MessagingAvailability::MODULE_TELEGRAM);

        return $this->modules->decryptSecret($settings['bot_token'] ?? null);
    }

    public function botUsername(Church $church): ?string
    {
        $username = $this->modules->settings($church, MessagingAvailability::MODULE_TELEGRAM)['bot_username'] ?? null;

        return filled($username) ? (string) $username : null;
    }

    /**
     * Telegram's own error text, e.g. "Forbidden: bot was blocked by the user".
     */
    public static function errorFrom(Response $response): string
    {
        return 'Telegram failed ('.$response->status().'): '.($response->json('description') ?? $response->body());
    }

    /**
     * Removes the bot token from a message (connection errors include the request URL).
     */
    public static function redact(string $message, ?string $botToken): string
    {
        return filled($botToken) ? str_replace((string) $botToken, '***', $message) : $message;
    }
}
