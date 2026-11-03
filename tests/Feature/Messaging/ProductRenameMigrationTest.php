<?php

namespace Tests\Feature\Messaging;

use App\Enums\MessageTemplatePurpose;
use App\Models\Church;
use App\Models\MessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductRenameMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_product_name_is_replaced_in_message_templates_and_other_wording_is_kept(): void
    {
        $church = Church::factory()->create();
        $template = MessageTemplate::query()->create([
            'church_id' => $church->id,
            'name' => 'Set password',
            'purpose' => MessageTemplatePurpose::Other,
            'subject' => 'Join WorshipPlanner at {{church}}',
            'body' => '<p>Our own words. You are invited to WorshipPlanner.</p>',
            'text_body' => 'You are invited to WorshipPlanner.',
        ]);

        (require base_path('database/migrations/2026_11_03_131814_rename_product_in_message_templates.php'))->up();

        $template->refresh();
        $this->assertSame('Join WorshipPlanning at {{church}}', $template->subject);
        $this->assertSame('<p>Our own words. You are invited to WorshipPlanning.</p>', $template->body);
        $this->assertSame('You are invited to WorshipPlanning.', $template->text_body);
    }
}
