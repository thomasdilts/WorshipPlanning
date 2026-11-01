<?php

namespace Tests\Feature\AssignedTasks;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentState;
use App\Filament\Pages\MyTasks;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Teams\Pages\ViewTeam;
use App\Filament\Resources\Teams\RelationManagers\AssignedTasksRelationManager;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TeamType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AssignedTasksListTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_tasks_defaults_to_today_and_forward(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $member = User::factory()->create([
            'church_id' => $church->id,
            'display_name' => 'Alex',
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($member, 'Member');
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $template = TaskTemplate::factory()->create(['church_id' => $church->id]);

        $pastTask = $this->taskForUser($church, $member, $template, now()->subDays(3), 'Past task');
        $todayTask = $this->taskForUser($church, $member, $template, now(), 'Today task');
        $futureTask = $this->taskForUser($church, $member, $template, now()->addDays(5), 'Future task');

        $other = User::factory()->create(['church_id' => $church->id]);
        $someoneElse = $this->taskForUser($church, $other, $template, now()->addDay(), 'Not mine');

        $this->actingAs($member);

        Livewire::test(MyTasks::class)
            ->assertCanSeeTableRecords([$todayTask, $futureTask])
            ->assertCanNotSeeTableRecords([$pastTask, $someoneElse]);
    }

    public function test_my_schedule_includes_tasks_for_teams_the_user_leads(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'teams');
        app(CurrentChurch::class)->set($church);

        $leader = User::factory()->create([
            'church_id' => $church->id,
            'display_name' => 'Alex',
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($leader, 'Member');

        $type = TeamType::factory()->create(['church_id' => $church->id]);
        $team = Team::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
            'name' => 'Choir',
        ]);
        $team->syncRoster([$leader->id], [$leader->id]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
        ]);
        $futureTask = $this->taskForTeam($church, $team, $template, now()->addDays(2), 'Sunday choir');

        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);
        $this->actingAs($leader);

        Livewire::test(MyTasks::class)
            ->assertCanSeeTableRecords([$futureTask])
            ->assertSee('Sunday choir')
            ->assertSee('Choir');
    }

    public function test_my_schedule_period_filter_can_show_past_and_all_events(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $member = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($member, 'Member');
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $template = TaskTemplate::factory()->create(['church_id' => $church->id]);
        $pastTask = $this->taskForUser($church, $member, $template, now()->subDays(3), 'Past task');
        $todayTask = $this->taskForUser($church, $member, $template, now(), 'Today task');
        $futureTask = $this->taskForUser($church, $member, $template, now()->addDays(3), 'Upcoming task');

        $this->actingAs($member);

        Livewire::test(MyTasks::class)
            ->assertCanSeeTableRecords([$todayTask, $futureTask])
            ->assertCanNotSeeTableRecords([$pastTask])
            ->filterTable('period', 'past')
            ->assertCanSeeTableRecords([$pastTask])
            ->assertCanNotSeeTableRecords([$todayTask, $futureTask])
            ->filterTable('period', 'all')
            ->assertCanSeeTableRecords([$pastTask, $todayTask, $futureTask]);
    }

    public function test_team_tasks_defaults_to_today_and_forward(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'teams');
        app(CurrentChurch::class)->set($church);

        $leader = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($leader, 'Member');

        $type = TeamType::factory()->create(['church_id' => $church->id]);
        $team = Team::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
            'name' => 'Choir',
        ]);
        $team->syncRoster([$leader->id], [$leader->id]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
        ]);

        $pastTask = $this->taskForTeam($church, $team, $template, now()->subDays(2), 'Past choir');
        $futureTask = $this->taskForTeam($church, $team, $template, now()->addDays(2), 'Future choir');

        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);
        $this->actingAs($leader);

        Livewire::test(AssignedTasksRelationManager::class, [
            'ownerRecord' => $team,
            'pageClass' => ViewTeam::class,
        ])
            ->assertCanSeeTableRecords([$futureTask])
            ->assertCanNotSeeTableRecords([$pastTask]);
    }

    public function test_team_tasks_from_date_filter_can_include_past(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'teams');
        app(CurrentChurch::class)->set($church);

        $leader = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($leader, 'Member');

        $type = TeamType::factory()->create(['church_id' => $church->id]);
        $team = Team::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
        ]);
        $team->syncRoster([$leader->id], [$leader->id]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
        ]);
        $pastTask = $this->taskForTeam($church, $team, $template, now()->subDays(2), 'Past choir');

        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);
        $this->actingAs($leader);

        Livewire::test(AssignedTasksRelationManager::class, [
            'ownerRecord' => $team,
            'pageClass' => ViewTeam::class,
        ])
            ->assertCanNotSeeTableRecords([$pastTask])
            ->set('tableFilters.from_date.date', null)
            ->assertCanSeeTableRecords([$pastTask]);
    }

    public function test_team_tasks_show_the_effective_assignment_status(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'teams');
        app(CurrentChurch::class)->set($church);

        $leader = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($leader, 'Member');

        $type = TeamType::factory()->create(['church_id' => $church->id]);
        $team = Team::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
        ]);
        $team->syncRoster([$leader->id], [$leader->id]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
        ]);
        $task = $this->taskForTeam($church, $team, $template, now()->addDays(2), 'Sunday choir');
        $task->update(['accepted_verbally_at' => now()]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);
        $this->actingAs($leader);

        Livewire::test(AssignedTasksRelationManager::class, [
            'ownerRecord' => $team,
            'pageClass' => ViewTeam::class,
        ])
            ->assertTableColumnStateSet('assignment_state', AssignmentState::ConfirmedVerbally, $task)
            ->assertSee('Accepted verbally');
    }

    public function test_my_schedule_links_each_task_to_its_event(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $member = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($member, 'Member');
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $template = TaskTemplate::factory()->create(['church_id' => $church->id]);
        $task = $this->taskForUser($church, $member, $template, now()->addDays(3), 'Upcoming task');

        $this->actingAs($member);

        Livewire::test(MyTasks::class)
            ->assertTableActionVisible('openEvent', $task)
            ->assertTableActionHasUrl('openEvent', EventResource::getUrl('view', ['record' => $task->event_id]), $task);
    }

    protected function taskForUser(
        Church $church,
        User $user,
        TaskTemplate $template,
        mixed $when,
        string $name,
    ): EventTask {
        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => $name.' event',
            'starts_at' => $when->copy()->setTime(10, 0),
            'ends_at' => $when->copy()->setTime(12, 0),
        ]);

        return EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $user->id,
            'name' => $name,
        ]);
    }

    protected function taskForTeam(
        Church $church,
        Team $team,
        TaskTemplate $template,
        mixed $when,
        string $name,
    ): EventTask {
        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => $name.' event',
            'starts_at' => $when->copy()->setTime(10, 0),
            'ends_at' => $when->copy()->setTime(12, 0),
        ]);

        return EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_team_id' => $team->id,
            'name' => $name,
        ]);
    }
}
