<?php

namespace Tests\Feature\SongStatistics;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Filament\Pages\SongStatistics;
use App\Filament\Resources\Songs\Pages\ListSongs;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\Song;
use App\Models\TaskTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\SongStatistics\Services\SongUsageStatistics;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SongStatisticsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($this->church, 'song_statistics');
        app(ChurchModuleManager::class)->enable($this->church, 'song_list');

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
    }

    public function test_aggregates_catalog_and_manual_songs(): void
    {
        $song = Song::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Amazing Grace',
            'author' => 'Newton',
        ]);

        $template = TaskTemplate::factory()->create(['church_id' => $this->church->id]);

        $eventA = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => '2026-11-01 10:00:00',
        ]);
        $eventB = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => '2026-11-08 10:00:00',
        ]);

        EventTask::factory()->create([
            'event_id' => $eventA->id,
            'task_template_id' => $template->id,
            'song_id' => $song->id,
            'song' => $song->name,
        ]);
        EventTask::factory()->create([
            'event_id' => $eventB->id,
            'task_template_id' => $template->id,
            'song_id' => $song->id,
            'song' => $song->name,
        ]);
        EventTask::factory()->create([
            'event_id' => $eventA->id,
            'task_template_id' => $template->id,
            'song_id' => null,
            'song' => 'Brand New Chorus',
        ]);
        EventTask::factory()->create([
            'event_id' => $eventB->id,
            'task_template_id' => $template->id,
            'song_id' => null,
            'song' => 'brand new chorus',
        ]);

        $rows = app(SongUsageStatistics::class)->forChurch($this->church);

        $this->assertCount(2, $rows);

        $catalog = $rows->get('catalog:'.$song->id);
        $this->assertNotNull($catalog);
        $this->assertSame('catalog', $catalog['source']);
        $this->assertSame(2, $catalog['usage_count']);
        $this->assertSame(2, $catalog['event_count']);
        $this->assertSame('Amazing Grace', $catalog['title']);
        $this->assertSame('Newton', $catalog['author']);

        $manual = $rows->get('manual:brand new chorus');
        $this->assertNotNull($manual);
        $this->assertSame('manual', $manual['source']);
        $this->assertSame(2, $manual['usage_count']);
        $this->assertNull($manual['song_id']);
    }

    public function test_page_renders_for_admin(): void
    {
        $song = Song::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Amazing Grace',
            'author' => 'Newton',
        ]);
        $template = TaskTemplate::factory()->create(['church_id' => $this->church->id]);
        $event = Event::factory()->create(['church_id' => $this->church->id]);
        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'song_id' => $song->id,
            'song' => $song->name,
        ]);
        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'song_id' => null,
            'song' => 'Manual Only',
        ]);

        $this->actingAs($this->admin);

        $component = Livewire::test(SongStatistics::class)
            ->assertSuccessful()
            ->assertSee('Song statistics')
            ->assertSee('Amazing Grace')
            ->assertSee('Manual Only')
            ->assertSee('Newton');

        $component
            ->set('tableFilters', [
                'source' => ['value' => 'manual'],
            ])
            ->assertSee('Manual Only')
            ->assertDontSee('Amazing Grace');
    }

    public function test_page_forbidden_when_module_disabled(): void
    {
        app(ChurchModuleManager::class)->disable($this->church, 'song_statistics');
        $this->admin->unsetRelation('church');

        $this->actingAs($this->admin)
            ->get('/admin/song-statistics')
            ->assertForbidden();
    }

    public function test_songs_list_shows_statistics_action_when_allowed(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListSongs::class)
            ->assertSuccessful()
            ->assertActionVisible('statistics');

        app(ChurchModuleManager::class)->disable($this->church, 'song_statistics');
        $this->admin->unsetRelation('church');

        Livewire::test(ListSongs::class)
            ->assertSuccessful()
            ->assertActionHidden('statistics');
    }

    public function test_ignores_other_church_tasks(): void
    {
        $other = Church::factory()->create();
        $song = Song::factory()->create(['church_id' => $other->id, 'name' => 'Other']);
        $template = TaskTemplate::factory()->create(['church_id' => $other->id]);
        $event = Event::factory()->create(['church_id' => $other->id]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'song_id' => $song->id,
            'song' => 'Other',
        ]);

        $rows = app(SongUsageStatistics::class)->forChurch($this->church);

        $this->assertCount(0, $rows);
    }
}
