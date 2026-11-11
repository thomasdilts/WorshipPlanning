<?php

namespace Tests\Feature\Events;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentResponseStatus;
use App\Enums\FieldUsage;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\Unavailability;
use App\Models\User;
use App\Services\AssignmentStateResolver;
use App\Services\EventPdfExporter;
use App\Services\EventTaskRowSummary;
use App\Services\EventTaskTimeline;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EventPdfExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_sidebar_and_service_details_show_names_without_prefixes_and_keep_team_labels(): void
    {
        $church = Church::factory()->create();
        app(CurrentChurch::class)->set($church);
        app(ChurchModuleManager::class)->enable($church, 'teams');
        $event = Event::factory()->create(['church_id' => $church->id]);
        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id, 'use_globally' => true,
            'person_usage' => FieldUsage::Optional, 'team_usage' => FieldUsage::Optional,
            'description_usage' => FieldUsage::Optional,
        ]);
        $person = User::factory()->create(['church_id' => $church->id, 'display_name' => 'Alex']);
        $team = Team::factory()->create(['church_id' => $church->id, 'name' => 'Choir']);
        $task = EventTask::factory()->create([
            'event_id' => $event->id, 'task_template_id' => $template->id,
            'assigned_user_id' => $person->id, 'assigned_team_id' => $team->id,
            'description' => 'Welcome guests',
        ]);
        $event->load(['church', 'tasks.template', 'tasks.assignedUser', 'tasks.assignedTeam', 'tasks.sharedFiles', 'tasks.pictures', 'tasks.catalogSong']);
        $summary = app(EventTaskRowSummary::class);

        foreach ([$summary->html($task, $event)->toHtml(), View::make('pdf.event', ['event' => $event, 'summary' => $summary])->render()] as $html) {
            $this->assertStringContainsString('Alex', $html);
            $this->assertStringContainsString('Welcome guests', $html);
            $this->assertStringContainsString('Team: Choir', $html);
            $this->assertStringNotContainsString('Person: ', $html);
            $this->assertStringNotContainsString('Desc: ', $html);
        }
    }

    public function test_announcements_render_as_numbered_points_in_the_order_and_pdf_with_safe_text(): void
    {
        $church = Church::factory()->create();
        app(CurrentChurch::class)->set($church);
        $event = Event::factory()->create(['church_id' => $church->id]);
        $template = TaskTemplate::factory()->create(['church_id' => $church->id, 'name' => 'Pålysningar', 'description_usage' => FieldUsage::Optional]);
        $task = EventTask::factory()->create([
            'event_id' => $event->id, 'task_template_id' => $template->id, 'name' => 'Pålysningar',
            'description' => "1. Church lunch\n2. Youth <script>alert(1)</script>\n3. Choir practice",
        ]);
        $event->load(['church', 'tasks.template', 'tasks.assignedUser', 'tasks.assignedTeam', 'tasks.sharedFiles', 'tasks.pictures', 'tasks.catalogSong']);
        $summary = app(EventTaskRowSummary::class);

        foreach ([$summary->html($task, $event)->toHtml(), View::make('pdf.event', ['event' => $event, 'summary' => $summary])->render()] as $html) {
            $this->assertStringContainsString('<ol class="wp-announcement-points">', $html);
            $this->assertStringContainsString('<li>Church lunch</li>', $html);
            $this->assertStringContainsString('<li>Choir practice</li>', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);
            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringNotContainsString('Desc: 1.', $html);
        }
    }

    public function test_print_hides_empty_extra_content_absence_and_planning_warnings(): void
    {
        $church = Church::factory()->create();
        app(CurrentChurch::class)->set($church);
        app(ChurchModuleManager::class)->enable($church, 'unavailability');
        $event = Event::factory()->create(['church_id' => $church->id]);
        $extra = TaskTemplate::factory()->create(['church_id' => $church->id, 'name' => 'Extra innehåll', 'description_usage' => FieldUsage::Required, 'person_usage' => FieldUsage::NotUsed]);
        EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $extra->id, 'name' => 'Extra innehåll', 'description' => null, 'assigned_user_id' => null, 'song' => null, 'song_id' => null, 'special_needs' => null]);
        $absence = TaskTemplate::factory()->create(['church_id' => $church->id, 'name' => 'Frånvaro', 'use_globally' => true]);
        EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $absence->id, 'name' => 'Frånvaro']);
        $template = TaskTemplate::factory()->create(['church_id' => $church->id, 'name' => 'Reading', 'description_usage' => FieldUsage::Required, 'person_usage' => FieldUsage::NotUsed]);
        $reader = User::factory()->create(['church_id' => $church->id, 'display_name' => 'Absent Reader']);
        Unavailability::factory()->create(['church_id' => $church->id, 'user_id' => $reader->id, 'starts_on' => $event->starts_at->toDateString(), 'ends_on' => $event->starts_at->toDateString()]);
        $task = EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $template->id, 'name' => 'Reading', 'description' => null, 'starts_at' => '10:00:00', 'duration' => 5, 'assigned_user_id' => $reader->id]);
        $event->load(['church', 'tasks.template', 'tasks.assignedUser', 'tasks.assignedTeam', 'tasks.sharedFiles', 'tasks.pictures', 'tasks.catalogSong']);

        $html = View::make('pdf.event', ['event' => $event, 'summary' => app(EventTaskRowSummary::class)])->render();

        $this->assertStringNotContainsString('Extra innehåll', $html);
        $this->assertStringNotContainsString('Frånvaro', $html);
        $this->assertStringNotContainsString('Missing: Description', $html);
        $this->assertStringNotContainsString('Absent Reader is unavailable', $html);
        $this->assertStringContainsString('Reading', $html);
        $this->assertContains('Missing: Description', app(EventTaskRowSummary::class)->problems($task, $event));
        $this->assertContains('Absent Reader is unavailable', app(EventTaskRowSummary::class)->problems($task, $event));
    }

    public function test_print_keeps_extra_content_when_it_has_a_description(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id]);
        $template = TaskTemplate::factory()->create(['church_id' => $church->id, 'name' => 'Extra innehåll', 'description_usage' => FieldUsage::Optional]);
        EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $template->id, 'name' => 'Extra innehåll', 'description' => 'Baptism after the closing song']);
        $event->load(['church', 'tasks.template', 'tasks.assignedUser', 'tasks.assignedTeam', 'tasks.sharedFiles', 'tasks.pictures', 'tasks.catalogSong']);

        $html = View::make('pdf.event', ['event' => $event, 'summary' => app(EventTaskRowSummary::class)])->render();

        $this->assertStringContainsString('Extra innehåll', $html);
        $this->assertStringContainsString('Baptism after the closing song', $html);
        $this->assertStringNotContainsString('Desc: ', $html);
    }

    public function test_service_order_prints_on_one_landscape_page_with_responsibilities_at_the_side(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id]);
        $timed = TaskTemplate::factory()->create(['church_id' => $church->id, 'use_globally' => false, 'person_usage' => FieldUsage::NotUsed]);
        $global = TaskTemplate::factory()->create(['church_id' => $church->id, 'use_globally' => true, 'person_usage' => FieldUsage::NotUsed]);
        EventTask::factory()->count(20)->sequence(fn ($sequence) => ['sort_order' => $sequence->index + 1])->create([
            'event_id' => $event->id, 'task_template_id' => $timed->id,
            'duration' => 5, 'starts_at' => '10:00:00', 'ends_at' => '10:05:00',
        ]);
        EventTask::factory()->count(6)->create(['event_id' => $event->id, 'task_template_id' => $global->id, 'name' => 'Coordinator']);

        $response = app(EventPdfExporter::class)->stream($event);
        $pdf = $response->getContent();

        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0[.0]*\s+0[.0]*\s+841[.\d]*\s+595[.\d]*\s*\]/', $pdf);

        $html = View::make('pdf.event', ['event' => $event, 'summary' => app(EventTaskRowSummary::class)])->render();
        $this->assertStringContainsString('<div class="people">', $html);
        $this->assertStringContainsString('Coordinator', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_service_order_marks_the_start_time_between_before_and_after_activities(): void
    {
        $church = Church::factory()->create();
        $event = Event::factory()->create(['church_id' => $church->id, 'starts_at' => '2026-11-01 10:00:00']);
        $timed = TaskTemplate::factory()->create(['church_id' => $church->id, 'use_globally' => false, 'person_usage' => FieldUsage::NotUsed]);
        EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $timed->id, 'name' => 'Opening song', 'sort_order' => 1, 'duration' => 5]);
        EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $timed->id, 'name' => 'Sound check', 'sort_order' => 9, 'duration' => 20, 'before_start' => true]);
        app(EventTaskTimeline::class)->recalculate($event);

        $html = View::make('pdf.event', ['event' => $event->fresh(['tasks.template']), 'summary' => app(EventTaskRowSummary::class)])->render();

        $this->assertStringContainsString('Start 10:00', $html);
        $this->assertMatchesRegularExpression('/Sound check.*class="start-marker".*Opening song/s', $html);
        $this->assertStringContainsString('09:40', $html);
    }

    public function test_event_pdf_stream_returns_pdf_response(): void
    {
        $church = Church::factory()->create(['name' => 'PDF Church']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sunday Service',
        ]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'name' => 'Welcome',
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $admin->id,
            'sort_order' => 1,
        ]);

        app(CurrentChurch::class)->set($church);

        $response = app(EventPdfExporter::class)->stream($event);

        $this->assertTrue(
            str_contains($response->headers->get('content-type'), 'pdf')
            || str_contains((string) $response->headers->get('content-disposition'), '.pdf')
        );
    }

    public function test_authenticated_user_can_open_event_pdf_inline_via_route(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sunday Service',
            'starts_at' => '2026-11-01 18:30:00',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $this->actingAs($admin)
            ->get(route('events.pdf', $event))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename=sunday-service-2026-11-01.pdf');
    }

    public function test_pdf_view_matches_service_order_columns_and_flags_problems(): void
    {
        $church = Church::factory()->create(['name' => 'Hope Church']);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'name' => 'Welcome',
            'person_usage' => FieldUsage::Required,
            'song_usage' => FieldUsage::Optional,
            'description_usage' => FieldUsage::Optional,
        ]);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sunday Service',
            'description' => 'Morning gathering',
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'name' => 'Welcome',
            'description' => 'Greet at doors',
            'song' => 'Amazing Grace',
            'assigned_user_id' => null,
            'starts_at' => '10:00:00',
            'duration' => 5,
            'sort_order' => 1,
        ]);

        $confirmed = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'name' => 'Reading',
            'assigned_user_id' => User::factory()->create(['church_id' => $church->id])->id,
            'starts_at' => '10:05:00',
            'duration' => 5,
            'sort_order' => 2,
        ]);

        EventTaskResponse::factory()->create([
            'church_id' => $church->id,
            'event_task_id' => $confirmed->id,
            'assignee_user_id' => $confirmed->assigned_user_id,
            'status' => AssignmentResponseStatus::Confirmed,
        ]);

        $event->load([
            'church',
            'tasks.template',
            'tasks.assignedUser',
            'tasks.assignedTeam',
            'tasks.sharedFiles',
            'tasks.pictures',
            'tasks.catalogSong',
            'tasks.responses',
        ]);

        $html = View::make('pdf.event', [
            'event' => $event,
            'summary' => app(EventTaskRowSummary::class),
            'states' => app(AssignmentStateResolver::class)->forMany($event->tasks),
        ])->render();

        $this->assertStringContainsString('Start', $html);
        $this->assertStringContainsString('Mins', $html);
        $this->assertStringContainsString('Status', $html);
        $this->assertStringContainsString('Details', $html);
        $this->assertStringContainsString('10:00', $html);
        $this->assertStringContainsString('Greet at doors', $html);
        $this->assertStringNotContainsString('Desc: ', $html);
        $this->assertStringContainsString('Song: Amazing Grace', $html);
        $this->assertStringNotContainsString('Person: ', $html);
        $this->assertStringContainsString('Missing: Person', $html);
        $this->assertStringContainsString('Accepted', $html);
        $this->assertStringNotContainsString('Hope Church', $html);
        $this->assertStringNotContainsString('Morning gathering', $html);
        $this->assertStringNotContainsString('>Global<', $html);
    }
}
