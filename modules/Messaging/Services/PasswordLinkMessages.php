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
 * first by the person's preferred messaging, then by email.
 *
 * When neither has a template chosen (or sending fails), the caller sends the built-in email instead.
 */
class PasswordLinkMessages
{
    public function __construct(
        protected MessageSender $sender,
        protected ChurchModuleManager $modules,
        protected TemplateMessages $templates,
        protected MessagePlaceholders $placeholders,
        protected MessagingAvailability $availability,
    ) {}

    /**
     * Whether the person's preferred SMS or WhatsApp is set up to carry this password link.
     */
    public function canSendByPreferredChannel(User $user, PasswordLinkKind $kind): bool
    {
        $preference = $user->message_preference ?? MessagePreference::Email;

        if ($preference === MessagePreference::Email || $user->church === null) {
            return false;
        }

        $channel = collect($this->sender->channels())->first(fn ($channel): bool => $channel->key() === $preference->value);

        return $channel !== null
            && $this->availability->isChannelEnabled($user->church, $preference)
            && $channel->isConfigured($user->church)
            && $this->hasTemplate($user, $kind, $preference);
    }

    /**
     * Sends the link and returns whether it went out; false means the built-in email should be sent.
     */
    public function send(User $user, PasswordLinkKind $kind, string $url, ?User $sentBy = null): bool
    {
        $preference = $user->message_preference ?? MessagePreference::Email;
        $preferences = $preference === MessagePreference::Email
            ? [MessagePreference::Email]
            : [$preference, MessagePreference::Email];

        foreach ($preferences as $channel) {
            $message = $this->message($user, $kind, $channel, $url, $sentBy);

            if ($message !== null && $this->sender->sendVia($user, $channel, $message)->success) {
                return true;
            }
        }

        return false;
    }

    protected function hasTemplate(User $user, PasswordLinkKind $kind, MessagePreference $preference): bool
    {
        return $preference === MessagePreference::WhatsApp
            ? filled($this->whatsAppTemplateName($user, $kind))
            : $this->messageTemplate($user, $kind, $preference) !== null;
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

        if ($preference === MessagePreference::WhatsApp) {
            $name = $this->whatsAppTemplateName($user, $kind);

            if (blank($name)) {
                return null;
            }

            return new OutboundMessageData(
                subject: $kind->label(),
                body: $kind->label(),
                templateKey: $kind->value,
                meta: $meta,
                whatsappTemplate: $name,
                placeholders: [...$this->placeholders->valuesFor($user, null, collect()), ...$values],
            );
        }

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

    protected function whatsAppTemplateName(User $user, PasswordLinkKind $kind): string
    {
        return (string) ($this->settings($user, MessagingAvailability::MODULE_WHATSAPP)[$kind->whatsAppSettingKey()] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function settings(User $user, string $moduleKey): array
    {
        return $user->church === null ? [] : $this->modules->settings($user->church, $moduleKey);
    }
}
