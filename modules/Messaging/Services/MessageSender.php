<?php

namespace Modules\Messaging\Services;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\MessagePreference;
use App\Models\OutboundMessage;
use App\Models\User;
use Modules\Messaging\Contracts\MessageChannel;
use Modules\Messaging\Data\OutboundMessageData;
use Modules\Messaging\Data\SendResult;
use Modules\Messaging\Support\MessagingAvailability;

class MessageSender
{
    protected MessagingAvailability $availability;

    /** @param  iterable<MessageChannel>  $channels */
    public function __construct(
        protected iterable $channels,
        protected ChurchModuleManager $modules,
    ) {
        $this->availability = new MessagingAvailability($modules);
    }

    public function sendToUser(User $user, OutboundMessageData $message, ?MessagePreference $forceChannel = null): SendResult
    {
        $church = $user->church;

        if ($church === null || ! $this->availability->anyEnabled($church)) {
            return SendResult::fail('none', 'No messaging module is enabled for this church.');
        }

        $preference = $forceChannel
            ?? $user->message_preference
            ?? MessagePreference::Email;

        if (! $this->availability->isChannelEnabled($church, $preference)) {
            if ($preference !== MessagePreference::Email
                && $this->availability->isChannelEnabled($church, MessagePreference::Email)) {
                return $this->sendToUser($user, $message, MessagePreference::Email);
            }

            return SendResult::fail(
                $preference->value,
                "Channel [{$preference->value}] is not enabled for this church.",
            );
        }

        // A message template without a Meta template cannot reach WhatsApp reliably, so it goes by email.
        if ($preference === MessagePreference::WhatsApp
            && blank($message->whatsappTemplate)
            && $this->availability->isChannelEnabled($church, MessagePreference::Email)) {
            return $this->sendToUser($user, $message, MessagePreference::Email);
        }

        $channel = $this->resolveChannel($preference->value);

        if ($channel === null) {
            return SendResult::fail($preference->value, "Unknown channel [{$preference->value}].");
        }

        if (! $channel->isConfigured($church)) {
            if ($preference !== MessagePreference::Email
                && $this->availability->isChannelEnabled($church, MessagePreference::Email)) {
                return $this->sendToUser($user, $message, MessagePreference::Email);
            }

            return SendResult::fail($preference->value, "Channel [{$preference->value}] is not configured.");
        }

        $result = $channel->send($user, $message);
        $this->log($user, $message, $result);

        return $result;
    }

    /**
     * Sends through exactly this channel — no fallback to email — e.g. to confirm a new preference works.
     */
    public function sendVia(User $user, MessagePreference $preference, OutboundMessageData $message): SendResult
    {
        $church = $user->church;

        if ($church === null || ! $this->availability->isChannelEnabled($church, $preference)) {
            return SendResult::fail($preference->value, __(':channel is not enabled for this church.', ['channel' => $preference->label()]));
        }

        $channel = $this->resolveChannel($preference->value);

        if ($channel === null || ! $channel->isConfigured($church)) {
            return SendResult::fail($preference->value, __(':channel is not set up yet.', ['channel' => $preference->label()]));
        }

        $result = $channel->send($user, $message);

        if ($user->exists) {
            $this->log($user, $message, $result);
        }

        return $result;
    }

    /**
     * Messages marked "sensitive" (password links) are logged without their body, so the history
     * never shows a working password link.
     */
    protected function log(User $user, OutboundMessageData $message, SendResult $result): void
    {
        $body = match (true) {
            (bool) ($message->meta['sensitive'] ?? false) => __('Not stored because it contains a password link.'),
            $result->channel === MessagePreference::Email->value => $message->body,
            default => $message->plainText(),
        };

        OutboundMessage::query()->create([
            'church_id' => $user->church_id,
            'event_id' => $message->meta['event_id'] ?? null,
            'event_message_id' => $message->meta['event_message_id'] ?? null,
            'user_id' => $user->id,
            'channel' => $result->channel,
            'subject' => $message->subject,
            'body' => $body,
            'status' => $result->success ? 'sent' : 'failed',
            'error' => $result->error,
            'template_key' => $message->templateKey,
            'meta' => $message->meta,
        ]);
    }

    protected function resolveChannel(string $key): ?MessageChannel
    {
        foreach ($this->channels as $channel) {
            if ($channel->key() === $key) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * @return list<MessageChannel>
     */
    public function channels(): array
    {
        return is_array($this->channels) ? $this->channels : iterator_to_array($this->channels);
    }
}
