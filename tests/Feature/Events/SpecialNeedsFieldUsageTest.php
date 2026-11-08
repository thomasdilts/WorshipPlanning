<?php

namespace Tests\Feature\Events;

use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\TasksRelationManager;
use App\Filament\Resources\TaskTemplates\Pages\CreateTaskTemplate;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SpecialNeedsFieldUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_can_set_special_needs_usage(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $this->actingAs($admin);

        Livewire::test(CreateTaskTemplate::class)
            ->fillForm([
                'name' => 'AV cue',
                'default_order' => 1,
                'default_duration' => 5,
                'person_usage' => FieldUsage::Optional->value,
                'team_usage' => FieldUsage::NotUsed->value,
                'description_usage' => FieldUsage::NotUsed->value,
                'special_needs_usage' => FieldUsage::Required->value,
                'song_usage' => FieldUsage::NotUsed->value,
                'picture_usage' => FieldUsage::NotUsed->value,
                'file_usage' => FieldUsage::NotUsed->value,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $template = TaskTemplate::query()->where('name', 'AV cue')->first();
        $this->assertNotNull($template);
        $this->assertSame(FieldUsage::Required, $template->special_needs_usage);
    }

    public function test_special_needs_hidden_when_template_marks_unused(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'special_needs_usage' => FieldUsage::NotUsed,
        ]);
        $event = Event::factory()->create(['church_id' => $church->id]);

        $this->actingAs($admin);

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('create', data: [
                'task_template_id' => $template->id,
                'name' => 'Reader',
                'special_needs' => 'Should be cleared',
                'sort_order' => 1,
                'duration' => 5,
            ])
            ->assertHasNoTableActionErrors();

        $task = EventTask::query()->where('event_id', $event->id)->first();
        $this->assertNotNull($task);
        $this->assertNull($task->special_needs);
    }

    public function test_task_can_be_created_with_template_required_fields_left_blank(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'person_usage' => FieldUsage::Required,
            'special_needs_usage' => FieldUsage::Required,
        ]);
        $event = Event::factory()->create(['church_id' => $church->id]);

        $this->actingAs($admin);

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('create', data: [
                'task_template_id' => $template->id,
                'name' => 'Reader',
                'sort_order' => 1,
                'duration' => 5,
            ])
            ->assertHasNoTableActionErrors();

        $task = $event->tasks()->sole();
        $this->assertNull($task->assigned_user_id);
        $this->assertNull($task->special_needs);
    }

    public function test_editing_a_task_can_clear_fields_the_template_requires(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'person_usage' => FieldUsage::Required,
            'special_needs_usage' => FieldUsage::Required,
        ]);
        $event = Event::factory()->create(['church_id' => $church->id]);
        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $admin->id,
            'special_needs' => 'Needs a stool',
        ]);

        $this->actingAs($admin);

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('edit', $task, data: [
                'assigned_user_id' => null,
                'special_needs' => null,
            ])
            ->assertHasNoTableActionErrors();

        $task->refresh();
        $this->assertNull($task->assigned_user_id);
        $this->assertNull($task->special_needs);
    }
}
