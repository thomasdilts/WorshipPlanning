<?php

namespace Modules\Messaging\Support;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use App\Support\OutboundUrlGuard;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use LogicException;

/**
 * The only way church email leaves WorshipPlanning: through the SMTP settings in the church's
 * email module. A church without them (or with the module off) cannot send email at all —
 * there is deliberately no fallback to the system mailer in .env.
 *
 * Each church gets its own named mailer that is rebuilt on every use, and the sender is set per
 * message, so a long-running queue worker never leaks one church's server or address into
 * another church's email.
 */
class ChurchMailer
{
    public function __construct(
        protected ChurchModuleManager $modules,
    ) {}

    public function isConfigured(?Church $church): bool
    {
        if ($church === null || ! $this->modules->isEnabled($church, MessagingAvailability::MODULE_EMAIL)) {
            return false;
        }

        $settings = $this->settings($church);

        return filled($settings['host'] ?? null)
            && filled($settings['from_address'] ?? null);
    }

    /**
     * Register the church's SMTP mailer and return its name.
     *
     * @throws LogicException when the church has no usable email settings
     */
    public function mailerFor(Church $church): string
    {
        $this->ensureConfigured($church);

        $settings = $this->settings($church);
        $encryption = $settings['encryption'] ?? 'tls';
        $name = 'church_smtp_'.$church->id;

        /* The host is typed in by the church: never let it reach this server's own network. */
        if (! in_array((int) ($settings['port'] ?? 587), OutboundUrlGuard::SMTP_PORTS, true)
            || app(OutboundUrlGuard::class)->problemWithHost((string) $settings['host']) !== null) {
            throw new LogicException('The church SMTP server address is not allowed.');
        }

        Config::set('mail.mailers.'.$name, [
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : null,
            'auto_tls' => $encryption !== '',
            'host' => $settings['host'],
            'port' => (int) ($settings['port'] ?? 587),
            'username' => ($settings['username'] ?? null) ?: null,
            'password' => $this->modules->decryptSecret($settings['password'] ?? null) ?: null,
            'timeout' => 15,
        ]);

        Mail::purge($name);

        return $name;
    }

    /**
     * @return array{address: string, name: string}
     */
    public function senderFor(Church $church): array
    {
        $this->ensureConfigured($church);

        $settings = $this->settings($church);

        return [
            'address' => (string) $settings['from_address'],
            'name' => ($settings['from_name'] ?? null) ?: $church->name,
        ];
    }

    /**
     * Route a notification email through the church's mailbox.
     *
     * @throws LogicException when the church has no usable email settings
     */
    public function applyTo(MailMessage $message, ?Church $church): MailMessage
    {
        $this->ensureConfigured($church);

        $sender = $this->senderFor($church);

        return $message->mailer($this->mailerFor($church))->from($sender['address'], $sender['name']);
    }

    /**
     * Notification channels for an email that may only go out through the church's mailbox.
     *
     * @return list<string>
     */
    public function channelsFor(?Church $church): array
    {
        return $this->isConfigured($church) ? ['mail'] : [];
    }

    protected function ensureConfigured(?Church $church): void
    {
        if (! $this->isConfigured($church)) {
            throw new LogicException('This church has no email settings, so it cannot send email.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function settings(Church $church): array
    {
        return $this->modules->settings($church, MessagingAvailability::MODULE_EMAIL)['email'] ?? [];
    }
}
