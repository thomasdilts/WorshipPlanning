<?php

namespace Modules\Messaging\Services;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\MessagePreference;
use App\Enums\PasswordLinkKind;
use App\Models\MessageTemplate;
use App\Models\User;
use Modules\Messaging\Data\OutboundMessageData;
use Modules\Messaging\Support\MessagePlaceholders;
use Modules\Messaging\Support\MessagingAvailability;

/**
 * Sends a set-password or reset-password link from the templates chosen in the channel settings:
 * first by SMS, ntfy or Telegram when the person prefers that, then by email. WhatsApp never carries password links,
 * because Meta does not accept link templates for them as Utility, so WhatsApp users get the email.
 *
 * When neither has a template chosen (or sending fails), the caller sends the built-in email instead.
 */
class PasswordLinkMessages
{
    /**
     * Preferred channels that may carry a password link before falling back to email.
     */
    protected const LINK_CHANNELS = [MessagePreference::Sms, MessagePreference::Ntfy, MessagePreference::Telegram];

    public function __construct(
        protected MessageSender $sender,
        protected ChurchModuleManager $modules,
        protected TemplateMessages $templates,
        protected MessagingAvailability $availability,
    ) {}

    /**
     * Whether the person's preferred SMS, ntfy or Telegram is set up to carry this password link.
     */
    public function canSendByPreferredChannel(User $user, PasswordLinkKind $kind): bool
    {
        $preference = $user->message_preference ?? MessagePreference::Email;

        if (! in_array($preference, self::LINK_CHANNELS, true) || $user->church === null) {
            return false;
        }

        $channel = collect($this->sender->channels())->first(fn ($channel): bool => $channel->key() === $preference->value);

        return $channel !== null
            && $this->availability->isChannelEnabled($user->church, $preference)
            && $channel->isConfigured($user->church)
            && $this->messageTemplate($user, $kind, $preference) !== null;
    }

    /**
     * Sends the link and returns whether it went out; false means the built-in email should be sent.
     */
    public function send(User $user, PasswordLinkKind $kind, string $url, ?User $sentBy = null): bool
    {
        $preference = $user->message_preference ?? MessagePreference::Email;
        $preferences = in_array($preference, self::LINK_CHANNELS, true)
            ? [$preference, MessagePreference::Email]
            : [MessagePreference::Email];

        foreach ($preferences as $channel) {
            $message = $this->message($user, $kind, $channel, $url, $sentBy);

            if ($message !== null && $this->sender->sendVia($user, $channel, $message)->success) {
                return true;
            }
        }

        return false;
    }

    /**
     * The message for this channel, or null when its settings choose no template.
     */
    protected function message(User $user, PasswordLinkKind $kind, MessagePreference $preference, string $url, ?User $sentBy): ?OutboundMessageData
    {
        $meta = ['kind' => $kind->value, 'sensitive' => true];
        $values = [
            MessagePlaceholders::SENDER => ($sentBy ?? $user)->displayName(),
            MessagePlaceholders::PASSWORD_URL => $url,
            MessagePlaceholders::EXPIRE_MINUTES => (string) (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
        ];

        $template = $this->messageTemplate($user, $kind, $preference);

        if ($template === null) {
            return null;
        }

        return $this->templates->compose($template, $user, meta: $meta, fallbackSubject: $kind->label(), values: $values);
    }

    protected function messageTemplate(User $user, PasswordLinkKind $kind, MessagePreference $preference): ?MessageTemplate
    {
        $templateId = $this->settings($user, $this->availability->moduleKeyFor($preference))[$kind->templateSettingKey()] ?? null;

        if (blank($templateId)) {
            return null;
        }

        return MessageTemplate::query()
            ->withoutGlobalScope('church')
            ->where('church_id', $user->church_id)
            ->find($templateId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function settings(User $user, string $moduleKey): array
    {
        return $user->church === null ? [] : $this->modules->settings($user->church, $moduleKey);
    }
}
