<?php

namespace App\Enums;

enum MessagePreference: string
{
    case Email = 'email';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Ntfy = 'ntfy';
    case Telegram = 'telegram';

    public function label(): string
    {
        return match ($this) {
            self::Email => __('Email'),
            self::Sms => __('SMS'),
            self::WhatsApp => __('WhatsApp'),
            self::Ntfy => __('ntfy'),
            self::Telegram => __('Telegram'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
