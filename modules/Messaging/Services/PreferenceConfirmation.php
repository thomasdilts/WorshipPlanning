<?php

namespace Modules\Messaging\Services;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\MessagePreference;
use App\Models\Language;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Support\Traits\Localizable;
use Modules\Messaging\Data\OutboundMessageData;
use Modules\Messaging\Data\SendResult;
use Modules\Messaging\Support\MessagePlaceholders;
use Modules\Messaging\Support\MessagingAvailability;

/**
 * Confirms a newly chosen messaging type through that channel: email, SMS and ntfy send the message template
 * chosen in their settings, WhatsApp the Meta template chosen in the WhatsApp settings.
 */
class PreferenceConfirmation
{
    use Localizable;

    public function __construct(
        protected MessageSender $sender,
        protected ChurchModuleManager $modules,
        protected TemplateMessages $templates,
        protected MessagePlaceholders $placeholders,
    ) {}

    public function send(User $recipient, MessagePreference $preference, ?int $languageId = null): SendResult
    {
        $language = Language::query()->find($languageId ?? $recipient->language_id);
        $locale = $language?->iso ?? app()->getLocale();

        if ($language !== null) {
            $recipient->setRelation('language', $language);
        }

        $message = $this->withLocale($locale, fn (): OutboundMessageData|SendResult => match ($preference) {
            MessagePreference::Email => $this->templateMessage(
                $recipient,
                $preference,
                MessagingAvailability::MODULE_EMAIL,
                __('No confirmation template is chosen in the email settings.'),
            ),
            MessagePreference::Sms => $this->templateMessage(
                $recipient,
                $preference,
                MessagingAvailability::MODULE_SMS,
                __('No confirmation template is chosen in the SMS settings.'),
            ),
            MessagePreference::WhatsApp => $this->whatsAppMessage($recipient),
            MessagePreference::Ntfy => $this->templateMessage(
                $recipient,
                $preference,
                MessagingAvailability::MODULE_NTFY,
                __('No confirmation template is chosen in the ntfy settings.'),
            ),
        });

        if ($message instanceof SendResult) {
            return $message;
        }

        return $this->sender->sendVia($recipient, $preference, $message);
    }

    /**
     * The message template chosen in the channel's settings.
     */
    protected function templateMessage(User $recipient, MessagePreference $preference, string $moduleKey, string $missing): OutboundMessageData|SendResult
    {
        $settings = $recipient->church === null
            ? []
            : $this->modules->settings($recipient->church, $moduleKey);
        $template = filled($settings['confirmation_template_id'] ?? null)
            ? MessageTemplate::query()->withoutGlobalScope('church')
                ->where('church_id', $recipient->church_id)
                ->find($settings['confirmation_template_id'])
            : null;

        if ($template === null) {
            return SendResult::fail($preference->value, $missing);
        }

        return $this->templates->compose(
            $template,
            $recipient,
            meta: ['kind' => 'preference_confirmation'],
            values: $this->senderValues($recipient),
        );
    }

    protected function whatsAppMessage(User $recipient): OutboundMessageData|SendResult
    {
        $settings = $recipient->church === null
            ? []
            : $this->modules->settings($recipient->church, MessagingAvailability::MODULE_WHATSAPP);
        $name = (string) ($settings['confirmation_whatsapp_template'] ?? '');

        if ($name === '') {
            return SendResult::fail(MessagePreference::WhatsApp->value, __('No confirmation template is chosen in the WhatsApp settings.'));
        }

        $builtIn = $this->builtInMessage(MessagePreference::WhatsApp);

        return new OutboundMessageData(
            subject: $builtIn->subject,
            body: $builtIn->body,
            templateKey: 'preference_confirmation',
            whatsappTemplate: $name,
            placeholders: [...$this->placeholders->valuesFor($recipient, null, collect()), ...$this->senderValues($recipient)],
        );
    }

    /**
     * {{message_sender}} is whoever changed the preference: the person themselves or an admin.
     *
     * @return array<string, string>
     */
    protected function senderValues(User $recipient): array
    {
        $sender = auth()->user();

        return [MessagePlaceholders::SENDER => $sender instanceof User ? $sender->displayName() : $recipient->displayName()];
    }

    protected function builtInMessage(MessagePreference $preference): OutboundMessageData
    {
        return new OutboundMessageData(
            subject: __('Preferred messaging'),
            body: __('Your preferred messaging type is :type', ['type' => $preference->label()]),
            templateKey: 'preference_confirmation',
        );
    }
}
