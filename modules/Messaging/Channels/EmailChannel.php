<?php

namespace Modules\Messaging\Channels;

use App\Models\Church;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Modules\Messaging\Contracts\MessageChannel;
use Modules\Messaging\Data\OutboundMessageData;
use Modules\Messaging\Data\SendResult;
use Modules\Messaging\Mail\GenericMessageMail;
use Modules\Messaging\Support\ChurchMailer;

class EmailChannel implements MessageChannel
{
    public function __construct(
        protected ChurchMailer $mailer,
    ) {}

    public function key(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return __('Email');
    }

    public function isConfigured(Church $church): bool
    {
        return $this->mailer->isConfigured($church);
    }

    public function send(User $recipient, OutboundMessageData $message): SendResult
    {
        $church = $recipient->church;

        if ($church === null) {
            return SendResult::fail($this->key(), 'Recipient has no church.');
        }

        if (! $this->isConfigured($church)) {
            return SendResult::fail($this->key(), 'Email channel is not configured for this church.');
        }

        if (! filled($recipient->email)) {
            return SendResult::fail($this->key(), 'Recipient has no email address.');
        }

        $mailerName = $this->mailer->mailerFor($church);
        $sender = $this->mailer->senderFor($church);

        try {
            Mail::mailer($mailerName)->to($recipient->email, $recipient->display_name ?: $recipient->name)
                ->send((new GenericMessageMail($message->subject, $message->body))->from($sender['address'], $sender['name']));

            return SendResult::ok($this->key());
        } catch (\Throwable $e) {
            // Not the raw error: it can quote whatever the configured server answered.
            report($e);

            return SendResult::fail($this->key(), 'The church SMTP server could not be reached or refused the message.');
        }
    }
}
