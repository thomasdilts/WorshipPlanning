<?php

namespace Modules\Messaging\Support;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\MessagePreference;
use App\Models\Church;

/**
 * Maps MessagePreference ↔ module keys and which channels a church can use.
 */
class MessagingAvailability
{
    public const MODULE_EMAIL = 'messaging';

    public const MODULE_SMS = 'messaging_sms';

    public const MODULE_WHATSAPP = 'messaging_whatsapp';

    public const MODULE_NTFY = 'messaging_ntfy';

    public function __construct(
        protected ChurchModuleManager $modules,
    ) {}

    public function moduleKeyFor(MessagePreference $preference): string
    {
        return match ($preference) {
            MessagePreference::Email => self::MODULE_EMAIL,
            MessagePreference::Sms => self::MODULE_SMS,
            MessagePreference::WhatsApp => self::MODULE_WHATSAPP,
            MessagePreference::Ntfy => self::MODULE_NTFY,
        };
    }

    public function preferenceForModule(string $moduleKey): ?MessagePreference
    {
        return match ($moduleKey) {
            self::MODULE_EMAIL => MessagePreference::Email,
            self::MODULE_SMS => MessagePreference::Sms,
            self::MODULE_WHATSAPP => MessagePreference::WhatsApp,
            self::MODULE_NTFY => MessagePreference::Ntfy,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public function messagingModuleKeys(): array
    {
        return [self::MODULE_EMAIL, self::MODULE_SMS, self::MODULE_WHATSAPP, self::MODULE_NTFY];
    }

    public function anyEnabled(?Church $church): bool
    {
        if ($church === null) {
            return false;
        }

        foreach ($this->messagingModuleKeys() as $key) {
            if ($this->modules->isEnabled($church, $key)) {
                return true;
            }
        }

        return false;
    }

    public function isChannelEnabled(?Church $church, MessagePreference $preference): bool
    {
        if ($church === null) {
            return false;
        }

        return $this->modules->isEnabled($church, $this->moduleKeyFor($preference));
    }

    /**
     * Channels the church has turned on (module enabled).
     *
     * @return list<MessagePreference>
     */
    public function enabledPreferences(?Church $church): array
    {
        if ($church === null) {
            return [];
        }

        $enabled = [];

        foreach (MessagePreference::cases() as $preference) {
            if ($this->isChannelEnabled($church, $preference)) {
                $enabled[] = $preference;
            }
        }

        return $enabled;
    }

    public function enabledCount(?Church $church): int
    {
        return count($this->enabledPreferences($church));
    }

    /**
     * Show “preferred messaging” when more than one messaging module is on.
     */
    public function shouldAskPreference(?Church $church): bool
    {
        return $this->enabledCount($church) >= 2;
    }

    /**
     * @return array<string, string> value => label for enabled channels only
     */
    public function preferenceOptions(?Church $church): array
    {
        $options = [];

        foreach ($this->enabledPreferences($church) as $preference) {
            $options[$preference->value] = $preference->label();
        }

        return $options;
    }

    public function needsPhone(?Church $church): bool
    {
        if ($church === null) {
            return false;
        }

        return $this->isChannelEnabled($church, MessagePreference::Sms)
            || $this->isChannelEnabled($church, MessagePreference::WhatsApp);
    }
}
