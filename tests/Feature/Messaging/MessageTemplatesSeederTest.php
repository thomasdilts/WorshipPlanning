<?php

namespace Tests\Feature\Messaging;

use App\Models\Church;
use App\Models\MessageTemplate;
use Database\Seeders\MessageTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTemplatesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_starter_templates_for_every_church(): void
    {
        $churches = Church::factory()->count(2)->create();

        $this->seed(MessageTemplatesSeeder::class);

        foreach ($churches as $church) {
            $this->assertEqualsCanonicalizing(
                ['krava-svar', 'paminnelse-endast', 'custom_service_mess', 'team_custom_message', 'preferred_messageing', 'set_password', 'reset_password'],
                MessageTemplate::query()->withoutGlobalScope('church')->where('church_id', $church->id)->pluck('key')->all(),
            );
        }
        $this->assertDatabaseHas('message_templates', [
            'church_id' => $churches[0]->id,
            'key' => 'krava-svar',
            'whatsapp_template' => 'require_response',
            'is_active' => true,
        ]);
    }

    public function test_keeps_existing_templates_with_the_same_name_or_key(): void
    {
        $church = Church::factory()->create();
        MessageTemplatesSeeder::seedForChurch($church);
        MessageTemplate::query()->withoutGlobalScope('church')->where('key', 'krava-svar')->update(['text_body' => 'Edited']);
        MessageTemplate::query()->withoutGlobalScope('church')->where('key', 'paminnelse-endast')->update(['key' => 'renamed-key']);

        MessageTemplatesSeeder::seedForChurch($church);

        $templates = MessageTemplate::query()->withoutGlobalScope('church')->where('church_id', $church->id)->get();
        $this->assertCount(7, $templates);
        $this->assertSame('Edited', $templates->firstWhere('key', 'krava-svar')->text_body);
    }
}
