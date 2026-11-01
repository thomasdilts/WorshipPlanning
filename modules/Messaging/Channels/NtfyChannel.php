<?php

namespace Modules\Messaging\Channels;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Modules\Messaging\Contracts\MessageChannel;
use Modules\Messaging\Data\OutboundMessageData;
use Modules\Messaging\Data\SendResult;
use Modules\Messaging\Support\MessagingAvailability;

/**
 * ntfy push notifications — JSON POST to the server root ({"topic", "message", "title"}). Each person
 * subscribes to their own topic in the ntfy app. Sends the same text version as SMS.
 *
 * @see https://docs.ntfy.sh/publish/
 */
class NtfyChannel implements MessageChannel
{
    public const DEFAULT_SERVER = 'https://ntfy.sh';

    /** Larger messages are turned into file attachments by the server. */
    public const MAX_MESSAGE_BYTES = 4096;

    public const MAX_TITLE_LENGTH = 250;

    public function __construct(
        protected ChurchModuleManager $modules,
    ) {}

    public function key(): string
    {
        return 'ntfy';
    }

    public function label(): string
    {
        return __('ntfy');
    }

    public function isConfigured(Church $church): bool
    {
        if (! $this->modules->isEnabled($church, MessagingAvailability::MODULE_NTFY)) {
            return false;
        }

        $settings = $this->modules->settings($church, MessagingAvailability::MODULE_NTFY);

        return filled($settings['server_url'] ?? null);
    }

    public function send(User $recipient, OutboundMessageData $message): SendResult
    {
        $church = $recipient->church;

        if ($church === null) {
            return SendResult::fail($this->key(), 'Recipient has no church.');
        }

        if (! $this->isConfigured($church)) {
            return SendResult::fail($this->key(), 'ntfy is not configured for this church.');
        }

        $topic = trim((string) $recipient->ntfy_topic);

        if ($topic === '') {
            return SendResult::fail($this->key(), 'Recipient has no ntfy topic.');
        }

        $body = $message->plainText();

        if ($body === '') {
            return SendResult::fail($this->key(), 'Message body is empty.');
        }

        if (strlen($body) > self::MAX_MESSAGE_BYTES) {
            return SendResult::fail($this->key(), 'ntfy message exceeds '.self::MAX_MESSAGE_BYTES.' bytes.');
        }

        $settings = $this->modules->settings($church, MessagingAvailability::MODULE_NTFY);
        $server = rtrim((string) $settings['server_url'], '/');
        $token = $this->modules->decryptSecret($settings['access_token'] ?? null);
        $payload = ['topic' => $topic, 'message' => $body];

        if (filled($message->subject)) {
            $payload['title'] = mb_substr(trim($message->subject), 0, self::MAX_TITLE_LENGTH);
        }

        try {
            $request = Http::acceptJson()->asJson()->timeout(30);

            if (filled($token)) {
                $request = $request->withToken((string) $token);
            }

            $response = $request->post($server.'/', $payload);

            if (! $response->successful()) {
                return SendResult::fail(
                    $this->key(),
                    'ntfy failed ('.$response->status().'): '.($response->json('error') ?? $response->body()),
                );
            }

            return SendResult::ok($this->key(), $response->json('id'));
        } catch (\Throwable $e) {
            return SendResult::fail($this->key(), $e->getMessage());
        }
    }
}
