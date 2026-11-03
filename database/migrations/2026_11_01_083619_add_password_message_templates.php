<?php

use Database\Seeders\MessageTemplatesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives existing churches the "Set password" and "Reset password" message templates: in Swedish for
 * Swedish churches, otherwise the English starter templates. Churches that already have a template with
 * the same key or name keep theirs.
 */
return new class extends Migration
{
    private const KEYS = ['set_password', 'reset_password'];

    public function up(): void
    {
        $english = collect(MessageTemplatesSeeder::templates())
            ->whereIn('key', self::KEYS)
            ->map(fn (array $template): array => [...$template, 'purpose' => $template['purpose']->value])
            ->values()
            ->all();

        foreach (DB::table('churches')->select(['id', 'locale'])->get() as $church) {
            $templates = $church->locale === 'sv' ? $this->swedish() : $english;

            foreach ($templates as $template) {
                $exists = DB::table('message_templates')
                    ->where('church_id', $church->id)
                    ->where(fn ($query) => $query->where('key', $template['key'])->orWhere('name', $template['name']))
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('message_templates')->insert([
                    ...$template,
                    'church_id' => $church->id,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('message_templates')->whereIn('key', self::KEYS)->delete();
    }

    /**
     * @return list<array{name: string, key: string, purpose: string, subject: string, body: string, text_body: string, whatsapp_template: string}>
     */
    private function swedish(): array
    {
        return [
            [
                'name' => 'Sätt lösenord',
                'key' => 'set_password',
                'purpose' => 'other',
                'subject' => 'Sätt ditt lösenord för {{church}}',
                'body' => <<<'HTML'
                    <p>Välkommen till {{church}}, {{first_name}}!</p>
                    <p>{{message_sender}} har bjudit in dig till WorshipPlanning. Välj ditt eget lösenord för att komma igång.</p>
                    <table border="0" cellspacing="0" cellpadding="0">
                      <tr>
                        <td align="center" bgcolor="#0F766E" style="border-radius: 6px; padding: 12px 24px;">
                          <a href="{{password_url}}" target="_blank" style="font-family: Helvetica, Arial, sans-serif; font-size: 16px; font-weight: bold; color: #FFFFFF; text-decoration: none; display: block;">Sätt lösenord</a>
                        </td>
                      </tr>
                    </table>
                    <p>Länken går ut om {{expire_minutes}} minuter.</p>
                    <p>Om du inte väntade dig den här inbjudan kan du ignorera meddelandet.</p>
                    HTML,
                'text_body' => <<<'TEXT'
                    Välkommen till {{church}}, {{first_name}}! {{message_sender}} har bjudit in dig till WorshipPlanning. Välj ditt lösenord här: {{password_url}}
                    Länken går ut om {{expire_minutes}} minuter.
                    TEXT,
                'whatsapp_template' => 'satt_losenord',
            ],
            [
                'name' => 'Återställ lösenord',
                'key' => 'reset_password',
                'purpose' => 'other',
                'subject' => 'Återställ ditt lösenord för {{church}}',
                'body' => <<<'HTML'
                    <p>Hej {{first_name}}!</p>
                    <p>Du får det här meddelandet eftersom vi har fått en begäran om att återställa lösenordet för ditt konto.</p>
                    <table border="0" cellspacing="0" cellpadding="0">
                      <tr>
                        <td align="center" bgcolor="#0F766E" style="border-radius: 6px; padding: 12px 24px;">
                          <a href="{{password_url}}" target="_blank" style="font-family: Helvetica, Arial, sans-serif; font-size: 16px; font-weight: bold; color: #FFFFFF; text-decoration: none; display: block;">Återställ lösenord</a>
                        </td>
                      </tr>
                    </table>
                    <p>Länken går ut om {{expire_minutes}} minuter.</p>
                    <p>Om du inte har begärt att återställa lösenordet behöver du inte göra något.</p>
                    HTML,
                'text_body' => <<<'TEXT'
                    Hej {{first_name}}! Återställ ditt lösenord för {{church}} här: {{password_url}}
                    Länken går ut om {{expire_minutes}} minuter. Om du inte har bett om detta kan du ignorera meddelandet.
                    TEXT,
                'whatsapp_template' => 'aterstall_losenord',
            ],
        ];
    }
};
