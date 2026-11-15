<?php

namespace Tests\Feature\Events;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\TasksRelationManager;
use App\Filament\Resources\TaskTemplates\Pages\CreateTaskTemplate;
use App\Models\Church;
use App\Models\Event;
use App\Models\TaskTemplate;
use App\Models\TeamType;
use App\Models\User;
use App\Support\SeedsDefaultTeamTypes;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskTemplateFieldUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_set_field_usage_modes_on_template(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'teams');
        SeedsDefaultTeamTypes::for($church);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        app(CurrentChurch::class)->set($church);

        $musicType = TeamType::query()
            ->where('church_id', $church->id)
            ->where('name', 'Music team')
            ->firstOrFail();

        $this->actingAs($admin);

        Livewire::test(CreateTaskTemplate::class)
            ->fillForm([
                'name' => 'Opening song',
                'default_order' => 20,
                'person_usage' => FieldUsage::Optional->value,
                'team_usage' => FieldUsage::Required->value,
                'team_type_id' => $musicType->id,
                'description_usage' => FieldUsage::Optional->value,
                'song_usage' => FieldUsage::Required->value,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $template = TaskTemplate::query()->where('name', 'Opening song')->first();

        $this->assertNotNull($template);
        $this->assertSame(FieldUsage::Optional, $template->person_usage);
        $this->assertSame(FieldUsage::Required, $template->team_usage);
        $this->assertSame($musicType->id, $template->team_type_id);
        $this->assertSame(FieldUsage::Optional, $template->description_usage);
        $this->assertSame(FieldUsage::Required, $template->song_usage);
        $this->assertTrue($template->usage('song')->isRequired());
        $this->assertTrue($template->usage('team')->isRequired());
    }

    public function test_show_as_bullets_is_saved_only_while_the_description_is_used(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        app(CurrentChurch::class)->set($church);
        $this->actingAs($admin);

        Livewire::test(CreateTaskTemplate::class)
            ->fillForm(['name' => 'Notices', 'default_order' => 10, 'description_usage' => FieldUsage::Optional->value])
            ->assertFormFieldVisible('description_as_bullets')
            ->fillForm(['description_as_bullets' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(TaskTemplate::query()->where('name', 'Notices')->value('description_as_bullets'));

        Livewire::test(CreateTaskTemplate::class)
            ->fillForm(['name' => 'Offering', 'default_order' => 20, 'description_usage' => FieldUsage::Optional->value, 'description_as_bullets' => true])
            ->fillForm(['description_usage' => FieldUsage::NotUsed->value])
            ->assertFormFieldHidden('description_as_bullets')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse(TaskTemplate::query()->where('name', 'Offering')->value('description_as_bullets'));
    }

    public function test_event_task_persists_song_when_template_allows_it(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        app(CurrentChurch::class)->set($church);

        $event = Event::factory()->create(['church_id' => $church->id]);
        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'person_usage' => FieldUsage::NotUsed,
            'team_usage' => FieldUsage::NotUsed,
            'description_usage' => FieldUsage::NotUsed,
            'song_usage' => FieldUsage::Required,
        ]);

        $this->actingAs($admin);

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('create', data: [
                'task_template_id' => $template->id,
                'name' => 'Opening song',
                'song' => 'Amazing Grace',
                'sort_order' => 1,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('event_tasks', [
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'song' => 'Amazing Grace',
        ]);
    }
}
