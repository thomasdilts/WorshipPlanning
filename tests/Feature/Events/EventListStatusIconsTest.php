<?php

namespace Tests\Feature\Events;

use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\EventTaskRowSummary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EventListStatusIconsTest extends TestCase
{
    use RefreshDatabase;

    public function test_problem_flags_split_staffing_from_other_issues(): void
    {
        $church = Church::factory()->create();
        app(CurrentChurch::class)->set($church);

        $personTemplate = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
            'description_usage' => FieldUsage::NotUsed,
            'song_usage' => FieldUsage::NotUsed,
        ]);
        $songTemplate = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'person_usage' => FieldUsage::NotUsed,
            'team_usage' => FieldUsage::NotUsed,
            'description_usage' => FieldUsage::NotUsed,
            'song_usage' => FieldUsage::Required,
        ]);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'starts_at' => now()->addDay()->setTime(10, 0),
        ]);

        $staffingTask = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $personTemplate->id,
            'assigned_user_id' => null,
        ]);
        $otherTask = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $songTemplate->id,
            'song' => null,
            'song_id' => null,
        ]);

        $summary = app(EventTaskRowSummary::class);

        $this->assertSame(
            ['staffing' => true, 'other' => false],
            $summary->problemFlags($staffingTask->fresh(['template', 'assignedUser', 'assignedTeam']), $event)
        );
        $this->assertSame(
            ['staffing' => false, 'other' => true],
            $summary->problemFlags($otherTask->fresh(['template', 'sharedFiles', 'pictures']), $event)
        );
        $this->assertSame(
            ['staffing' => true, 'other' => true],
            $summary->eventProblemFlags($event->fresh())
        );
    }

    public function test_events_list_shows_description_and_status_icon_classes(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
            'song_usage' => FieldUsage::Required,
        ]);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sunday service',
            'description' => 'Communion Sunday',
            'starts_at' => now()->addDays(2)->setTime(10, 0),
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => null,
            'song' => null,
            'song_id' => null,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListEvents::class)
            ->assertSuccessful()
            ->assertSee('Communion Sunday')
            ->assertDontSee('Tasks by event')
            ->assertSeeHtml('wp-event-status-bad')
            ->assertSeeHtml('wp-event-status-icons');
    }
}
