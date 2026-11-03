<?php

namespace Database\Seeders;

use App\Enums\MessageTemplatePurpose;
use App\Models\Church;
use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Starter message templates (English). Only adds what a church is missing, so edited templates are never overwritten.
 */
class MessageTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Church::query()->cursor() as $church) {
            self::seedForChurch($church);
        }
    }

    public static function seedForChurch(Church $church): void
    {
        foreach (self::templates() as $template) {
            $exists = MessageTemplate::query()
                ->withoutGlobalScope('church')
                ->where('church_id', $church->id)
                ->where(fn ($query) => $query->where('key', $template['key'])->orWhere('name', $template['name']))
                ->exists();

            if ($exists) {
                continue;
            }

            MessageTemplate::query()->create([
                ...$template,
                'church_id' => $church->id,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @return list<array{name: string, key: string, purpose: MessageTemplatePurpose, subject: string, body: string, text_body: string, whatsapp_template: string}>
     */
    public static function templates(): array
    {
        return [
            [
                'name' => 'Require response',
                'key' => 'krava-svar',
                'purpose' => MessageTemplatePurpose::Service,
                'subject' => '{{church}}, {{task}}, {{event}}, {{date}}',
                'body' => <<<'HTML'
                    <p>You are scheduled to do the task {{task}} at {{event}} {{date}} {{time}}. Please let us know whether you can come and do the task by pressing one of the buttons below.</p>
                    <p>Thank you and God bless you!</p>
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                      <tr>
                        <td>
                          <table border="0" cellspacing="0" cellpadding="0">
                            <tr>
                              <td align="center" bgcolor="#90EE90" style="border-radius: 6px; padding: 12px 24px;">
                                <a href="{{accept_url}}" target="_blank" style="font-family: Helvetica, Arial, sans-serif; font-size: 16px; font-weight: bold; color: #14532D; text-decoration: none; display: block;">Yes, I can come</a>
                              </td>
                              <td width="12" style="font-size: 0; line-height: 0;">&nbsp;</td>
                              <td align="center" bgcolor="#FAA0A0" style="border-radius: 6px; padding: 12px 24px;">
                                <a href="{{decline_url}}" target="_blank" style="font-family: Helvetica, Arial, sans-serif; font-size: 16px; font-weight: bold; color: #7F1D1D; text-decoration: none; display: block;">No, I can't come</a>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>
                    HTML,
                'text_body' => <<<'TEXT'
                    You are scheduled to do the task {{task}} at {{event}} {{date}} {{time}}. Please let us know whether you can come.
                    Yes, I can come {{accept_link}}
                    No, I can't come {{decline_link}}
                    TEXT,
                'whatsapp_template' => 'require_response',
            ],
            [
                'name' => 'Reminder only',
                'key' => 'paminnelse-endast',
                'purpose' => MessageTemplatePurpose::Service,
                'subject' => 'Reminder {{task}}, {{event}}, {{date}}',
                'body' => <<<'HTML'
                    <p>This is just a reminder that you are scheduled to do the task {{task}} at {{event}} {{date}} {{time}}.</p>
                    <p>Thank you and God bless you!</p>
                    HTML,
                'text_body' => <<<'TEXT'
                    This is just a reminder that you are scheduled to do the task {{task}} at {{event}} {{date}} {{time}}.
                    Thank you and God bless you!
                    TEXT,
                'whatsapp_template' => 'reminder',
            ],
            [
                'name' => 'Service custom message',
                'key' => 'custom_service_mess',
                'purpose' => MessageTemplatePurpose::Service,
                'subject' => '{{church}}, {{task}}, {{event}}, {{date}}, update',
                'body' => <<<'HTML'
                    <p>This is a message from {{message_sender}} about {{task}} at {{event}} {{date}}:</p>
                    <p>{{custom_mess}}</p>
                    <p>Thank you and God bless you!</p>
                    HTML,
                'text_body' => <<<'TEXT'
                    This is a message from {{message_sender}} about {{task}} at {{event}} {{date}}:

                    {{custom_mess}}

                    Thank you and God bless you!
                    TEXT,
                'whatsapp_template' => 'service_custom_message',
            ],
            [
                'name' => 'Team custom message',
                'key' => 'team_custom_message',
                'purpose' => MessageTemplatePurpose::Team,
                'subject' => '{{team}} message',
                'body' => <<<'HTML'
                    <p>This is a message from {{message_sender}} to the team {{team}}:</p>
                    <p>{{custom_mess}}</p>
                    <p>Thank you and God bless you!</p>
                    HTML,
                'text_body' => <<<'TEXT'
                    This is a message from {{message_sender}} to the team {{team}}:

                    {{custom_mess}}

                    Thank you and God bless you!
                    TEXT,
                'whatsapp_template' => 'team_custom_message',
            ],
            [
                'name' => 'Preferred messaging',
                'key' => 'preferred_messageing',
                'purpose' => MessageTemplatePurpose::Other,
                'subject' => '{{message_sender}} has changed your preferred messaging',
                'body' => <<<'HTML'
                    <p>Hi {{first_name}}! {{message_sender}} has changed your preferred messaging.</p>
                    <p>From now on you will get messages from the worship planning system of {{church}} this way.</p>
                    HTML,
                'text_body' => <<<'TEXT'
                    Hi {{first_name}}! {{message_sender}} has changed your preferred messaging.

                    From now on you will get messages from the worship planning system of {{church}} this way.
                    TEXT,
                'whatsapp_template' => 'preferred_messaging',
            ],
            [
                'name' => 'Set password',
                'key' => 'set_password',
                'purpose' => MessageTemplatePurpose::Other,
                'subject' => 'Set your password for {{church}}',
                'body' => <<<'HTML'
                    <p>Welcome to {{church}}, {{first_name}}!</p>
                    <p>{{message_sender}} has invited you to WorshipPlanning. Choose your own password to get started.</p>
                    <table border="0" cellspacing="0" cellpadding="0">
                      <tr>
                        <td align="center" bgcolor="#0F766E" style="border-radius: 6px; padding: 12px 24px;">
                          <a href="{{password_url}}" target="_blank" style="font-family: Helvetica, Arial, sans-serif; font-size: 16px; font-weight: bold; color: #FFFFFF; text-decoration: none; display: block;">Set password</a>
                        </td>
                      </tr>
                    </table>
                    <p>This link expires in {{expire_minutes}} minutes.</p>
                    <p>If you did not expect this invitation, you can ignore this message.</p>
                    HTML,
                'text_body' => <<<'TEXT'
                    Welcome to {{church}}, {{first_name}}! {{message_sender}} has invited you to WorshipPlanning. Choose your password here: {{password_url}}
                    The link expires in {{expire_minutes}} minutes.
                    TEXT,
                'whatsapp_template' => 'set_password',
            ],
            [
                'name' => 'Reset password',
                'key' => 'reset_password',
                'purpose' => MessageTemplatePurpose::Other,
                'subject' => 'Reset your password for {{church}}',
                'body' => <<<'HTML'
                    <p>Hi {{first_name}}!</p>
                    <p>You are receiving this message because we received a password reset request for your account.</p>
                    <table border="0" cellspacing="0" cellpadding="0">
                      <tr>
                        <td align="center" bgcolor="#0F766E" style="border-radius: 6px; padding: 12px 24px;">
                          <a href="{{password_url}}" target="_blank" style="font-family: Helvetica, Arial, sans-serif; font-size: 16px; font-weight: bold; color: #FFFFFF; text-decoration: none; display: block;">Reset password</a>
                        </td>
                      </tr>
                    </table>
                    <p>This link expires in {{expire_minutes}} minutes.</p>
                    <p>If you did not request a password reset, no further action is required.</p>
                    HTML,
                'text_body' => <<<'TEXT'
                    Hi {{first_name}}! Reset your password for {{church}} here: {{password_url}}
                    The link expires in {{expire_minutes}} minutes. If you did not ask for this, ignore this message.
                    TEXT,
                'whatsapp_template' => 'reset_password',
            ],
        ];
    }
}
