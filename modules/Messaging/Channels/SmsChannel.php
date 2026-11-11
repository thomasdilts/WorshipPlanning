<?php

namespace Modules\Messaging\Channels;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use App\Models\User;
use App\Support\OutboundUrlGuard;
use Modules\Messaging\Contracts\MessageChannel;
use Modules\Messaging\Data\OutboundMessageData;
use Modules\Messaging\Data\SendResult;
use Modules\Messaging\Support\MessagingAvailability;
use Modules\Messaging\Support\PhoneNumber;

/**
 * iP.1 SMS API — POST https://api.ip1sms.com/v2/batches
 *
 * @see https://ip1sms.com/en/developer/sending-sms/
 */
class SmsChannel implements MessageChannel
{
    public function __construct(
        protected ChurchModuleManager $modules,
    ) {}

    public function key(): string
    {
        return 'sms';
    }

    public function label(): string
    {
        return __('SMS');
    }

    public function isConfigured(Church $church): bool
    {
        if (! $this->modules->isEnabled($church, MessagingAvailability::MODULE_SMS)) {
            return false;
        }

        $settings = $this->modules->settings($church, MessagingAvailability::MODULE_SMS);

        return filled($settings['api_token'] ?? null)
            && filled($settings['sender'] ?? null);
    }

    public function send(User $recipient, OutboundMessageData $message): SendResult
    {
        $church = $recipient->church;

        if ($church === null) {
            return SendResult::fail($this->key(), 'Recipient has no church.');
        }

        if (! $this->isConfigured($church)) {
            return SendResult::fail($this->key(), 'SMS (iP.1) is not configured for this church.');
        }

        $msisdn = PhoneNumber::toMsisdn($recipient->phone);

        if ($msisdn === null) {
            return SendResult::fail($this->key(), 'Recipient has no valid mobile number (MSISDN).');
        }

        $settings = $this->modules->settings($church, MessagingAvailability::MODULE_SMS);
        $token = $this->modules->decryptSecret($settings['api_token'] ?? null);
        $sender = (string) ($settings['sender'] ?? '');
        $base = rtrim((string) ($settings['api_base'] ?? 'https://api.ip1sms.com/v2'), '/');
        $body = $message->plainText();

        if ($body === '') {
            return SendResult::fail($this->key(), 'Message body is empty.');
        }

        if (mb_strlen($body) > 2000) {
            return SendResult::fail($this->key(), 'SMS body exceeds 2000 characters.');
        }

        try {
            $response = app(OutboundUrlGuard::class)->http($base)->withToken((string) $token)
                ->acceptJson()
                ->asJson()
                ->timeout(30)
                ->post($base.'/batches', [
                    'sender' => $sender,
                    'recipients' => [$msisdn],
                    'body' => $body,
                    'type' => 'sms',
                    'reference' => substr((string) ($message->meta['event_message_id'] ?? $message->templateKey ?? 'wp'), 0, 40),
                ]);

            if (! $response->successful()) {
                return SendResult::fail(
                    $this->key(),
                    'iP.1 SMS failed ('.$response->status().').',
                );
            }

            return SendResult::ok($this->key());
        } catch (\Throwable $e) {
            report($e);

            return SendResult::fail($this->key(), 'The SMS service could not be reached.');
        }
    }
}
