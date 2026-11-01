<?php

namespace App\Enums;

/**
 * The two messages that carry a password link: the invitation to set a first password, and a
 * requested password reset. Each channel's settings choose a template for each.
 */
enum PasswordLinkKind: string
{
    case SetPassword = 'set_password';
    case ResetPassword = 'reset_password';

    public function label(): string
    {
        return match ($this) {
            self::SetPassword => __('Set password'),
            self::ResetPassword => __('Reset password'),
        };
    }

    /**
     * Module setting holding the chosen message template ID (email and SMS settings).
     */
    public function templateSettingKey(): string
    {
        return $this->value.'_template_id';
    }

    /**
     * Module setting holding the chosen Meta template name (WhatsApp settings).
     */
    public function whatsAppSettingKey(): string
    {
        return $this->value.'_whatsapp_template';
    }
}
