<?php

namespace Tests\Feature\Events;

use App\Core\Tenancy\CurrentChurch;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalTaskOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_tasks_sort_above_timed_tasks_by_global_order(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $event = Event::factory()->create(['church_id' => $church->id]);

        $timed = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sermon',
            'use_globally' => false,
            'default_order' => 1,
        ]);

        $globalLate = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sound check',
            'use_globally' => true,
            'default_order' => 20,
        ]);

        $globalEarly = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'name' => 'Setup chairs',
            'use_globally' => true,
            'default_order' => 5,
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $timed->id,
            'name' => 'Sermon',
            'starts_at' => '10:30:00',
            'sort_order' => 1,
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $globalLate->id,
            'name' => 'Sound check',
            'starts_at' => null,
            'sort_order' => 99,
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $globalEarly->id,
            'name' => 'Setup chairs',
            'starts_at' => null,
            'sort_order' => 50,
        ]);

        $names = $event->fresh()->tasks()->with('template')->get()->map->displayName()->all();

        $this->assertSame(['Setup chairs', 'Sound check', 'Sermon'], $names);
    }
}
