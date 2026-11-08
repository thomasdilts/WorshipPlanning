<?php

namespace Tests\Feature\FileSharing;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\TasksRelationManager;
use App\Filament\Resources\SharedFiles\Pages\CreateSharedFile;
use App\Filament\Resources\Teams\Pages\ViewTeam;
use App\Filament\Resources\Teams\RelationManagers\SharedFilesRelationManager;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\SharedFile;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TeamType;
use App\Models\User;
use App\Services\EventCopier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FileSharingModuleTest extends TestCase
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
        app(ChurchModuleManager::class)->enable($this->church, 'file_sharing');
        app(ChurchModuleManager::class)->enable($this->church, 'teams');

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
    }

    public function test_admin_can_create_shared_file_and_share_with_team(): void
    {
        $type = TeamType::factory()->create(['church_id' => $this->church->id]);
        $team = Team::factory()->create([
            'church_id' => $this->church->id,
            'team_type_id' => $type->id,
            'name' => 'Band',
        ]);
        $team->syncRoster([$this->admin->id], [$this->admin->id]);

        $this->actingAs($this->admin);

        $file = UploadedFile::fake()->create('charts.pdf', 120, 'application/pdf');

        Livewire::test(CreateSharedFile::class)
            ->fillForm([
                'name' => 'Chord charts',
                'description' => 'Sunday set',
                'path' => [$file],
                'team_ids' => [$team->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('shared_files', [
            'church_id' => $this->church->id,
            'name' => 'Chord charts',
        ]);

        $shared = SharedFile::query()->where('name', 'Chord charts')->first();
        $this->assertNotNull($shared);
        $this->assertTrue($team->sharedFiles()->whereKey($shared->id)->exists());
    }

    public function test_team_leader_can_upload_file_on_team(): void
    {
        $leader = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($leader, 'Member');

        $type = TeamType::factory()->create(['church_id' => $this->church->id]);
        $team = Team::factory()->create([
            'church_id' => $this->church->id,
            'team_type_id' => $type->id,
        ]);
        $team->syncRoster([$leader->id], [$leader->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
        $this->actingAs($leader);

        $upload = UploadedFile::fake()->create('runsheet.docx', 80);

        Livewire::test(SharedFilesRelationManager::class, [
            'ownerRecord' => $team,
            'pageClass' => ViewTeam::class,
        ])
            ->callTableAction('uploadFile', data: [
                'name' => 'Run sheet',
                'path' => [$upload],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('shared_files', [
            'church_id' => $this->church->id,
            'name' => 'Run sheet',
            'uploaded_by_user_id' => $leader->id,
        ]);
        $this->assertTrue($team->sharedFiles()->where('name', 'Run sheet')->exists());
    }

    public function test_shared_files_nav_forbidden_when_module_disabled(): void
    {
        app(ChurchModuleManager::class)->disable($this->church, 'file_sharing');
        $this->admin->unsetRelation('church');

        $this->actingAs($this->admin)
            ->get('/admin/shared-files')
            ->assertForbidden();
    }

    public function test_event_task_can_attach_files_when_template_allows(): void
    {
        $shared = SharedFile::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Slide deck',
            'uploaded_by_user_id' => $this->admin->id,
        ]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'file_usage' => FieldUsage::Required,
            'special_needs_usage' => FieldUsage::NotUsed,
        ]);

        $event = Event::factory()->create(['church_id' => $this->church->id]);

        $this->actingAs($this->admin);

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('create', data: [
                'task_template_id' => $template->id,
                'name' => 'Projection',
                'shared_file_ids' => [$shared->id],
                'sort_order' => 1,
                'duration' => 5,
            ])
            ->assertHasNoTableActionErrors();

        $task = EventTask::query()->where('event_id', $event->id)->first();
        $this->assertNotNull($task);
        $this->assertTrue($task->sharedFiles->contains('id', $shared->id));
    }

    public function test_event_copy_reattaches_shared_files(): void
    {
        $shared = SharedFile::factory()->create([
            'church_id' => $this->church->id,
            'uploaded_by_user_id' => $this->admin->id,
        ]);
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'file_usage' => FieldUsage::Optional,
        ]);
        $source = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addWeek(),
        ]);
        $task = EventTask::factory()->create([
            'event_id' => $source->id,
            'task_template_id' => $template->id,
        ]);
        $task->sharedFiles()->attach($shared->id, ['sort_order' => 0]);

        $copy = app(EventCopier::class)->copy($source, [
            'starts_at' => now()->addWeeks(2),
        ])->first();

        $copiedTask = $copy->tasks->first();
        $this->assertNotNull($copiedTask);
        $this->assertTrue($copiedTask->sharedFiles->contains('id', $shared->id));
    }
}
