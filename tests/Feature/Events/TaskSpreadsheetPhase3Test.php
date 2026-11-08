<?php

namespace Tests\Feature\Events;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentResponseStatus;
use App\Enums\FieldUsage;
use App\Enums\SpreadsheetRangeKey;
use App\Enums\SpreadsheetViewKey;
use App\Filament\Resources\Events\Actions\EditEventTaskAction;
use App\Filament\Resources\Events\Pages\TaskSpreadsheet;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\User;
use App\Services\TaskSpreadsheetBuilder;
use App\Services\TaskSpreadsheetPreferences;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaskSpreadsheetPhase3Test extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($this->church, 'teams');

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
    }

    public function test_default_range_excludes_events_beyond_six_weeks(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);

        $near = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Soon',
            'starts_at' => now()->addWeeks(2),
        ]);
        $far = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Later',
            'starts_at' => now()->addWeeks(8),
        ]);

        EventTask::factory()->create([
            'event_id' => $near->id,
            'task_template_id' => $template->id,
        ]);
        EventTask::factory()->create([
            'event_id' => $far->id,
            'task_template_id' => $template->id,
        ]);

        [$from, $until] = SpreadsheetRangeKey::NextSixWeeks->bounds();
        $matrix = app(TaskSpreadsheetBuilder::class)->build($this->church, $from, $until);

        $this->assertSame(['Soon'], $matrix['rows']->pluck('event_name')->values()->all());
    }

    public function test_needs_attention_view_hides_confirmed_rows(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $person = User::factory()->create([
            'church_id' => $this->church->id,
            'display_name' => 'Confirmed Person',
        ]);

        $vacantEvent = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Needs filler',
            'starts_at' => now()->addDays(3),
        ]);
        $confirmedEvent = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'All set',
            'starts_at' => now()->addDays(4),
        ]);

        EventTask::factory()->create([
            'event_id' => $vacantEvent->id,
            'task_template_id' => $template->id,
        ]);
        $confirmedTask = EventTask::factory()->create([
            'event_id' => $confirmedEvent->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $person->id,
        ]);
        EventTaskResponse::factory()->create([
            'church_id' => $this->church->id,
            'event_task_id' => $confirmedTask->id,
            'assignee_user_id' => $person->id,
            'status' => AssignmentResponseStatus::Confirmed,
            'occurred_at' => now(),
        ]);

        $this->actingAs($this->admin);

        Livewire::test(TaskSpreadsheet::class)
            ->assertSee('Needs filler')
            ->assertSee('All set')
            ->call('setStaffingView', SpreadsheetViewKey::NeedsAttention->value)
            ->assertSee('Needs filler')
            ->assertDontSeeHtml('>All set</div>');
    }

    public function test_multiple_activities_in_one_column_open_a_chooser(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Song',
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addDays(5),
        ]);
        $first = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'name' => 'Opening song',
            'sort_order' => 1,
        ]);
        $second = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'name' => 'Closing song',
            'sort_order' => 2,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(TaskSpreadsheet::class)
            ->assertSuccessful()
            ->assertSee('2 activities')
            ->assertSeeHtml("mountAction('chooseEventTask'")
            ->mountAction('chooseEventTask', arguments: [
                'taskIds' => [$first->id, $second->id],
            ])
            ->assertActionMounted('chooseEventTask')
            ->assertSee('Opening song')
            ->assertSee('Closing song');
    }

    public function test_spreadsheet_preferences_are_isolated_by_church(): void
    {
        $other = Church::factory()->create();
        $prefs = app(TaskSpreadsheetPreferences::class);

        $prefs->remember($this->admin, $this->church, [
            'view' => SpreadsheetViewKey::NeedsAttention->value,
            'density' => 'compact',
        ]);
        $prefs->remember($this->admin, $other, [
            'view' => SpreadsheetViewKey::AllStaffing->value,
            'density' => 'comfortable',
        ]);

        $this->admin->refresh();

        $this->assertSame(
            SpreadsheetViewKey::NeedsAttention->value,
            $prefs->for($this->admin, $this->church)['view'],
        );
        $this->assertSame(
            SpreadsheetViewKey::AllStaffing->value,
            $prefs->for($this->admin, $other)['view'],
        );
        $this->assertSame('compact', $prefs->for($this->admin, $this->church)['density']);
        $this->assertSame('comfortable', $prefs->for($this->admin, $other)['density']);
    }

    public function test_event_filters_and_search_are_restored_when_returning_to_the_spreadsheet(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(TaskSpreadsheet::class)
            ->set('tableFilters.events.names', ['Morning service', 'Prayer meeting'])
            ->set('tableSearch', 'Anna');

        Livewire::test(TaskSpreadsheet::class)
            ->assertSuccessful()
            ->assertSet('tableFilters.events.names', ['Morning service', 'Prayer meeting'])
            ->assertSet('tableSearch', 'Anna');
    }

    public function test_selecting_no_event_types_is_preserved_when_returning(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Morning service',
            'starts_at' => now()->addDay(),
        ]);
        EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $template->id]);
        $this->actingAs($this->admin);

        Livewire::test(TaskSpreadsheet::class)->call('selectEventTypes', false);

        $component = Livewire::test(TaskSpreadsheet::class)
            ->assertSuccessful()
            ->assertSet('tableFilters.events.names', []);

        $this->assertCount(0, $component->instance()->getTableRecords());
    }

    public function test_url_filters_restore_custom_dates_instead_of_newer_preferences(): void
    {
        $this->actingAs($this->admin);
        app(TaskSpreadsheetPreferences::class)->remember($this->admin, $this->church, [
            'range' => 'custom',
            'from' => '2026-12-01',
            'until' => '2026-12-31',
        ]);

        Livewire::withQueryParams([
            'range' => 'custom',
            'filters' => [
                'custom_dates' => ['from' => '2026-11-01', 'until' => '2026-11-30'],
                'events' => ['names' => ['Morning service']],
            ],
            'search' => 'Anna',
        ])->test(TaskSpreadsheet::class)
            ->assertSuccessful()
            ->assertSet('customFrom', '2026-11-01')
            ->assertSet('customUntil', '2026-11-30')
            ->assertSet('tableFilters.events.names', ['Morning service'])
            ->assertSet('tableSearch', 'Anna');
    }

    public function test_event_filters_are_not_shared_between_users(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(TaskSpreadsheet::class)
            ->set('tableFilters.events.names', ['Only my selection'])
            ->set('tableSearch', 'Private search');
        $otherAdmin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($otherAdmin, 'Church Admin');

        $this->actingAs($otherAdmin);
        Livewire::test(TaskSpreadsheet::class)
            ->assertSuccessful()
            ->assertSet('tableFilters.events.names', [])
            ->assertSet('tableSearch', '');
    }

    public function test_editing_a_task_from_another_church_is_not_found(): void
    {
        $otherChurch = Church::factory()->create();
        $template = TaskTemplate::factory()->create([
            'church_id' => $otherChurch->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $event = Event::factory()->create([
            'church_id' => $otherChurch->id,
            'starts_at' => now()->addDays(2),
        ]);
        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
        ]);

        $this->actingAs($this->admin);

        $resolve = new \ReflectionMethod(EditEventTaskAction::class, 'resolveTask');

        $this->expectException(ModelNotFoundException::class);
        $resolve->invoke(null, ['taskId' => $task->id]);
    }

    public function test_my_team_needs_only_includes_the_users_teams(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Band',
            'person_usage' => FieldUsage::NotUsed,
            'team_usage' => FieldUsage::Required,
        ]);
        $myTeam = Team::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'My Choir',
        ]);
        $otherTeam = Team::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Other Band',
        ]);
        $this->admin->teams()->attach($myTeam->id, ['is_leader' => true]);

        $mine = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'My gap',
            'starts_at' => now()->addDays(3),
        ]);
        $theirs = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Their gap',
            'starts_at' => now()->addDays(4),
        ]);

        $myTask = EventTask::factory()->create([
            'event_id' => $mine->id,
            'task_template_id' => $template->id,
            'assigned_team_id' => $myTeam->id,
        ]);
        EventTaskResponse::factory()->create([
            'church_id' => $this->church->id,
            'event_task_id' => $myTask->id,
            'assignee_team_id' => $myTeam->id,
            'status' => AssignmentResponseStatus::Declined,
            'occurred_at' => now(),
        ]);
        EventTask::factory()->create([
            'event_id' => $theirs->id,
            'task_template_id' => $template->id,
            'assigned_team_id' => $otherTeam->id,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(TaskSpreadsheet::class)
            ->call('setStaffingView', SpreadsheetViewKey::MyTeamNeeds->value)
            ->assertSee('My gap')
            ->assertDontSeeHtml('>Their gap</div>');
    }
}
