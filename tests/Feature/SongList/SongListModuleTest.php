<?php

namespace Tests\Feature\SongList;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\TasksRelationManager;
use App\Filament\Resources\Songs\Pages\CreateSong;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\Song;
use App\Models\TaskTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\SongList\Services\SongCsvExporter;
use Modules\SongList\Services\SongCsvImporter;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SongListModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($this->church, 'song_list');

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
    }

    public function test_import_upserts_worshiphhn_csv(): void
    {
        $path = storage_path('framework/testing-songs.csv');
        file_put_contents($path, implode("\n", [
            'Name,Name2,Author,Description',
            '"Amazing Grace","Grace","Newton","Amazing grace how sweet the sound"',
            '"Holy Holy","",Author Unknown,"Holy holy holy"',
        ])."\n");

        $result = app(SongCsvImporter::class)->import($this->church, $path);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertDatabaseHas('songs', [
            'church_id' => $this->church->id,
            'name' => 'Amazing Grace',
            'name2' => 'Grace',
            'author' => 'Newton',
        ]);

        file_put_contents($path, implode("\n", [
            'Name,Name2,Author,Description',
            '"Amazing Grace","Grace (alt)","Newton","Updated lyrics"',
        ])."\n");

        $again = app(SongCsvImporter::class)->import($this->church, $path);

        $this->assertSame(0, $again['created']);
        $this->assertSame(1, $again['updated']);
        $this->assertDatabaseHas('songs', [
            'church_id' => $this->church->id,
            'name' => 'Amazing Grace',
            'name2' => 'Grace (alt)',
            'description' => 'Updated lyrics',
        ]);

        @unlink($path);
    }

    public function test_export_matches_import_headers(): void
    {
        Song::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Test Song',
            'name2' => 'Alt',
            'author' => 'Someone',
            'description' => "Line one\nLine two",
        ]);

        $response = app(SongCsvExporter::class)->download($this->church);
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringStartsWith('Name,Name2,Author,Description', $csv);
        $this->assertStringContainsString('Test Song', $csv);
        $this->assertStringContainsString('Line one', $csv);
    }

    public function test_songs_nav_forbidden_when_module_disabled(): void
    {
        app(ChurchModuleManager::class)->disable($this->church, 'song_list');
        $this->admin->unsetRelation('church');

        $this->actingAs($this->admin)
            ->get('/admin/songs')
            ->assertForbidden();
    }

    public function test_event_task_attaches_catalog_song(): void
    {
        $song = Song::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'How Great Thou Art',
        ]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'song_usage' => FieldUsage::Required,
            'allow_manual_song_entry' => false,
        ]);

        $event = Event::factory()->create(['church_id' => $this->church->id]);

        $this->actingAs($this->admin);

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('create', data: [
                'task_template_id' => $template->id,
                'name' => 'Worship',
                'song_id' => $song->id,
                'sort_order' => 1,
            ])
            ->assertHasNoTableActionErrors();

        $task = EventTask::query()->where('event_id', $event->id)->first();
        $this->assertNotNull($task);
        $this->assertSame($song->id, $task->song_id);
        $this->assertSame('How Great Thou Art', $task->song);
    }

    public function test_manual_song_entry_when_template_allows(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'song_usage' => FieldUsage::Required,
            'allow_manual_song_entry' => true,
        ]);

        $event = Event::factory()->create(['church_id' => $this->church->id]);

        $this->actingAs($this->admin);

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('create', data: [
                'task_template_id' => $template->id,
                'name' => 'Special',
                'song' => 'Brand new chorus',
                'sort_order' => 1,
            ])
            ->assertHasNoTableActionErrors();

        $task = EventTask::query()->where('event_id', $event->id)->first();
        $this->assertNotNull($task);
        $this->assertNull($task->song_id);
        $this->assertSame('Brand new chorus', $task->song);
    }

    public function test_required_song_accepts_catalog_manual_or_blank_when_both_allowed(): void
    {
        $song = Song::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Catalog pick',
        ]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'song_usage' => FieldUsage::Required,
            'allow_manual_song_entry' => true,
        ]);

        $event = Event::factory()->create(['church_id' => $this->church->id]);

        $this->actingAs($this->admin);

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('create', data: [
                'task_template_id' => $template->id,
                'name' => 'Catalog path',
                'song_id' => $song->id,
                'sort_order' => 1,
            ])
            ->assertHasNoTableActionErrors();

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('create', data: [
                'task_template_id' => $template->id,
                'name' => 'Missing both',
                'sort_order' => 2,
                'duration' => 5,
            ])
            ->assertHasNoTableActionErrors();
    }

    public function test_admin_can_create_song_with_lyrics(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateSong::class)
            ->fillForm([
                'name' => 'Psalm 23',
                'name2' => 'The Lord is my shepherd',
                'author' => 'Traditional',
                'description' => "The Lord is my shepherd\nI shall not want",
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('songs', [
            'church_id' => $this->church->id,
            'name' => 'Psalm 23',
            'author' => 'Traditional',
        ]);
    }
}
