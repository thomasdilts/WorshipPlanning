<?php

namespace Tests\Feature\Pictures;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\TasksRelationManager;
use App\Filament\Resources\Pictures\Pages\CreatePicture;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\Picture;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\EventCopier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PicturesModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($this->church, 'pictures');

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
    }

    public function test_admin_can_create_picture_in_library(): void
    {
        $this->actingAs($this->admin);

        $file = UploadedFile::fake()->image('stage.jpg');

        Livewire::test(CreatePicture::class)
            ->fillForm([
                'name' => 'Stage setup',
                'description' => 'Front of stage',
                'path' => [$file],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('pictures', [
            'church_id' => $this->church->id,
            'name' => 'Stage setup',
        ]);
    }

    public function test_pictures_nav_forbidden_when_module_disabled(): void
    {
        app(ChurchModuleManager::class)->disable($this->church, 'pictures');
        $this->admin->unsetRelation('church');

        $this->actingAs($this->admin)
            ->get('/admin/pictures')
            ->assertForbidden();
    }

    public function test_event_task_can_attach_pictures_when_template_allows(): void
    {
        $picture = Picture::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Banner',
        ]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'picture_usage' => FieldUsage::Required,
        ]);

        $event = Event::factory()->create(['church_id' => $this->church->id]);

        $this->actingAs($this->admin);

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('create', data: [
                'task_template_id' => $template->id,
                'name' => 'Visual',
                'picture_ids' => [$picture->id],
                'sort_order' => 1,
            ])
            ->assertHasNoTableActionErrors();

        $task = EventTask::query()->where('event_id', $event->id)->first();
        $this->assertNotNull($task);
        $this->assertTrue($task->pictures->contains('id', $picture->id));
    }

    public function test_event_copy_reattaches_pictures(): void
    {
        $picture = Picture::factory()->create(['church_id' => $this->church->id]);
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'picture_usage' => FieldUsage::Optional,
        ]);

        $source = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => '2026-11-01 10:00:00',
        ]);

        $task = EventTask::factory()->create([
            'event_id' => $source->id,
            'task_template_id' => $template->id,
        ]);
        $task->pictures()->sync([$picture->id => ['sort_order' => 0]]);

        $copy = app(EventCopier::class)->copy($source, [
            'starts_at' => '2026-11-08 10:00:00',
        ])->first();

        $copiedTask = $copy->tasks->first();
        $this->assertNotNull($copiedTask);
        $this->assertTrue($copiedTask->pictures->contains('id', $picture->id));
    }
}
