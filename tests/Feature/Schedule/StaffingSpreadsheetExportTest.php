<?php

namespace Tests\Feature\Schedule;

use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Enums\SpreadsheetViewKey;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\StaffingSpreadsheetExporter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StaffingSpreadsheetExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_includes_date_range_staffing_and_neutralizes_formulas(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00');

        $church = Church::factory()->create(['name' => 'Alpha Church']);
        $other = Church::factory()->create(['name' => 'Beta Church']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'name' => 'Welcome',
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);

        $person = User::factory()->create([
            'church_id' => $church->id,
            'name' => '=HYPERLINK("http://evil")',
            'display_name' => '=HYPERLINK("http://evil")',
        ]);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sunday Service',
            'starts_at' => '2026-11-01 10:00:00',
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $person->id,
        ]);

        Event::factory()->create([
            'church_id' => $other->id,
            'name' => 'Foreign Service',
            'starts_at' => '2026-11-01 10:00:00',
        ]);

        $rows = app(StaffingSpreadsheetExporter::class)->rows(
            $church,
            now(),
            now()->addWeeks(6),
            null,
            SpreadsheetViewKey::AllStaffing,
        );

        $this->assertSame(__('Date'), $rows[0][0]);
        $this->assertContains('Welcome', $rows[0]);
        $this->assertSame('2026-11-01', $rows[1][0]);
        $this->assertSame('Sunday Service', $rows[1][2]);
        $welcomeIndex = array_search('Welcome', $rows[0], true);
        $this->assertNotFalse($welcomeIndex);
        $this->assertStringStartsWith("'=", $rows[1][$welcomeIndex]);
        $this->assertStringNotContainsString('Foreign Service', json_encode($rows));
    }

    public function test_authenticated_user_can_download_staffing_csv_via_route(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        app(CurrentChurch::class)->set($church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $this->actingAs($admin)
            ->get(route('schedule.staffing-csv', [
                'view' => SpreadsheetViewKey::AllStaffing->value,
                'range' => 'next_six_weeks',
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition');
    }

    public function test_needs_attention_view_excludes_fully_staffed_rows(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00');

        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'name' => 'Welcome',
            'person_usage' => FieldUsage::Required,
        ]);

        $person = User::factory()->create(['church_id' => $church->id, 'display_name' => 'Alice']);

        $vacant = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Needs filler',
            'starts_at' => now()->addDays(3),
        ]);
        EventTask::factory()->create([
            'event_id' => $vacant->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => null,
        ]);

        $staffed = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'All set',
            'starts_at' => now()->addDays(4),
        ]);
        EventTask::factory()->create([
            'event_id' => $staffed->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $person->id,
        ]);

        $rows = app(StaffingSpreadsheetExporter::class)->rows(
            $church,
            now(),
            now()->addWeeks(6),
            null,
            SpreadsheetViewKey::NeedsAttention,
        );

        $body = collect($rows)->skip(1)->pluck(2)->all();

        $this->assertContains('Needs filler', $body);
        $this->assertNotContains('All set', $body);
    }
}
