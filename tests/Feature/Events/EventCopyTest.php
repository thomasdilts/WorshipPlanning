<?php

namespace Tests\Feature\Events;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\User;
use App\Services\EventCopier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EventCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_copy_event_with_optional_people_teams_and_songs(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $assignee = User::factory()->create(['church_id' => $church->id]);
        $team = Team::factory()->create(['church_id' => $church->id]);

        $source = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sunday Service',
            'description' => 'Original',
            'starts_at' => '2026-11-01 10:00:00',
        ]);

        $template = TaskTemplate::factory()->create(['church_id' => $church->id]);

        EventTask::factory()->create([
            'event_id' => $source->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $assignee->id,
            'assigned_team_id' => $team->id,
            'name' => 'Opening song',
            'song' => 'Amazing Grace',
            'starts_at' => '10:05:00',
            'sort_order' => 2,
        ]);

        $withoutAssignments = app(EventCopier::class)->copy($source, [
            'name' => 'Sunday Service (copy)',
            'description' => 'New series',
            'starts_at' => '2026-11-08 10:00:00',
            'copy_people_and_teams' => false,
            'copy_songs' => false,
        ])->first();

        $this->assertSame('Sunday Service (copy)', $withoutAssignments->name);
        $this->assertSame('New series', $withoutAssignments->description);
        $this->assertSame('2026-11-08 10:00:00', $withoutAssignments->starts_at->format('Y-m-d H:i:s'));
        $this->assertCount(1, $withoutAssignments->tasks);

        $task = $withoutAssignments->tasks->first();
        $this->assertNull($task->assigned_user_id);
        $this->assertNull($task->assigned_team_id);
        $this->assertNull($task->song);
        $this->assertSame('Opening song', $task->name);
        $this->assertSame('10:00:00', $task->starts_at, 'Task times are recalculated from the new start time.');

        $withAll = app(EventCopier::class)->copy($source, [
            'starts_at' => '2026-11-15 10:00:00',
            'copy_people_and_teams' => true,
            'copy_songs' => true,
        ])->first();

        $copied = $withAll->tasks->first();
        $this->assertSame($assignee->id, $copied->assigned_user_id);
        $this->assertSame($team->id, $copied->assigned_team_id);
        $this->assertSame('Amazing Grace', $copied->song);
    }

    public function test_copy_across_multiple_weeks(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $source = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sunday Service',
            'starts_at' => '2026-11-01 10:00:00',
        ]);

        $template = TaskTemplate::factory()->create(['church_id' => $church->id]);
        EventTask::factory()->create([
            'event_id' => $source->id,
            'task_template_id' => $template->id,
        ]);

        $copies = app(EventCopier::class)->copy($source, [
            'name' => 'Advent series',
            'starts_at' => '2026-11-08 10:00:00',
            'weeks' => 3,
        ]);

        $this->assertCount(3, $copies);
        $this->assertSame(
            ['2026-11-08', '2026-11-15', '2026-11-22'],
            $copies->map(fn (Event $e) => $e->starts_at->format('Y-m-d'))->all()
        );
        $this->assertTrue($copies->every(fn (Event $e) => $e->name === 'Advent series'));
        $this->assertTrue($copies->every(fn (Event $e) => $e->tasks->count() === 1));
    }

    public function test_list_page_copy_action_creates_event(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $source = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Midweek',
            'starts_at' => '2026-11-05 19:00:00',
        ]);

        $template = TaskTemplate::factory()->create(['church_id' => $church->id]);
        EventTask::factory()->create([
            'event_id' => $source->id,
            'task_template_id' => $template->id,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListEvents::class)
            ->callTableAction('copy', $source, data: [
                'name' => 'Midweek copy',
                'description' => 'Updated notes',
                'starts_at' => '2026-11-12 19:00:00',
                'weeks' => 2,
                'copy_people_and_teams' => false,
                'copy_songs' => true,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, Event::query()->where('name', 'Midweek copy')->count());
        $this->assertDatabaseHas('events', [
            'name' => 'Midweek copy',
            'starts_at' => '2026-11-12 19:00:00',
        ]);
        $this->assertDatabaseHas('events', [
            'name' => 'Midweek copy',
            'starts_at' => '2026-11-19 19:00:00',
        ]);
    }
}
