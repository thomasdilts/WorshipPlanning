<?php

namespace Tests\Feature\Schedule;

use App\Enums\AssignmentResponseStatus;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\EventTaskTimeline;
use App\Services\PersonalIcsExporter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PersonalIcsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_ics_contains_stable_uid_and_utc_window_for_own_upcoming_task(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00');

        $church = Church::factory()->create(['timezone' => 'Europe/Stockholm']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, 'Member');

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sunday Service',
            'starts_at' => '2026-11-01 10:00:00',
        ]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'name' => 'Welcome',
            'use_globally' => false,
        ]);

        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'name' => 'Welcome',
            'assigned_user_id' => $user->id,
            'duration' => 10,
            'sort_order' => 1,
        ]);

        app(EventTaskTimeline::class)->recalculate($event);

        EventTaskResponse::factory()->create([
            'church_id' => $church->id,
            'event_task_id' => $task->id,
            'assignee_user_id' => $user->id,
            'status' => AssignmentResponseStatus::Confirmed,
        ]);

        $ics = app(PersonalIcsExporter::class)->build($user->fresh()->load('church'));

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('UID:event-task-'.$task->id.'@worshipplanner', $ics);
        $this->assertStringContainsString('SUMMARY:Welcome — Sunday Service', $ics);
        $this->assertStringContainsString('STATUS:CONFIRMED', $ics);
        $this->assertMatchesRegularExpression('/DTSTART:\d{8}T\d{6}Z/', $ics);
        $this->assertMatchesRegularExpression('/DTEND:\d{8}T\d{6}Z/', $ics);
    }

    public function test_ics_skips_declined_and_past_and_other_church_tasks(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00');

        $churchA = Church::factory()->create();
        $churchB = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['church_id' => $churchA->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, 'Member');

        $other = User::factory()->create(['church_id' => $churchB->id]);

        $past = Event::factory()->create([
            'church_id' => $churchA->id,
            'name' => 'Past Service',
            'starts_at' => '2026-09-01 10:00:00',
        ]);
        EventTask::factory()->create([
            'event_id' => $past->id,
            'assigned_user_id' => $user->id,
            'name' => 'Past Welcome',
            'duration' => 10,
            'sort_order' => 1,
        ]);

        $declinedEvent = Event::factory()->create([
            'church_id' => $churchA->id,
            'name' => 'Declined Service',
            'starts_at' => '2026-11-08 10:00:00',
        ]);
        $declinedTask = EventTask::factory()->create([
            'event_id' => $declinedEvent->id,
            'assigned_user_id' => $user->id,
            'name' => 'Declined Welcome',
            'duration' => 10,
            'sort_order' => 1,
        ]);
        EventTaskResponse::factory()->create([
            'church_id' => $churchA->id,
            'event_task_id' => $declinedTask->id,
            'assignee_user_id' => $user->id,
            'status' => AssignmentResponseStatus::Declined,
        ]);

        $foreign = Event::factory()->create([
            'church_id' => $churchB->id,
            'name' => 'Foreign Service',
            'starts_at' => '2026-11-15 10:00:00',
        ]);
        EventTask::factory()->create([
            'event_id' => $foreign->id,
            'assigned_user_id' => $other->id,
            'name' => 'Foreign Welcome',
            'duration' => 10,
            'sort_order' => 1,
        ]);

        $keep = Event::factory()->create([
            'church_id' => $churchA->id,
            'name' => 'Keep Service',
            'starts_at' => '2026-11-22 10:00:00',
        ]);
        $keepTask = EventTask::factory()->create([
            'event_id' => $keep->id,
            'assigned_user_id' => $user->id,
            'name' => 'Keep Welcome',
            'duration' => 10,
            'sort_order' => 1,
        ]);

        $ics = app(PersonalIcsExporter::class)->build($user->fresh()->load('church'));

        $this->assertStringContainsString('Keep Welcome', $ics);
        $this->assertStringContainsString('UID:event-task-'.$keepTask->id.'@worshipplanner', $ics);
        $this->assertStringNotContainsString('Past Welcome', $ics);
        $this->assertStringNotContainsString('Declined Welcome', $ics);
        $this->assertStringNotContainsString('Foreign Welcome', $ics);
        $this->assertStringNotContainsString('Foreign Service', $ics);
    }

    public function test_authenticated_user_can_download_ics_via_route(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, 'Member');

        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $this->actingAs($user)
            ->get(route('schedule.ics'))
            ->assertOk()
            ->assertHeader('content-type', 'text/calendar; charset=utf-8')
            ->assertHeader('content-disposition');
    }

    public function test_escaping_handles_commas_semicolons_and_newlines(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00');

        $church = Church::factory()->create(['name' => 'Hope; Church, Stockholm']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, 'Member');

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Service; Morning, A',
            'starts_at' => '2026-11-01 10:00:00',
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'assigned_user_id' => $user->id,
            'name' => 'Welcome, Door; A',
            'duration' => 10,
            'sort_order' => 1,
        ]);

        $ics = app(PersonalIcsExporter::class)->build($user->fresh()->load('church'));

        $this->assertStringContainsString('SUMMARY:Welcome\\, Door\\; A — Service\\; Morning\\, A', $ics);
        $this->assertStringContainsString('Hope\\; Church\\, Stockholm', $ics);
    }
}
