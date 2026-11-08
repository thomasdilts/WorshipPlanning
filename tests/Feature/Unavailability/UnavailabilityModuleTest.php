<?php

namespace Tests\Feature\Unavailability;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Filament\Pages\MyUnavailability;
use App\Filament\Resources\Teams\Pages\ViewTeam;
use App\Filament\Resources\Teams\RelationManagers\UnavailabilitiesRelationManager;
use App\Filament\Resources\Unavailabilities\Pages\ListUnavailabilities;
use App\Filament\Resources\Unavailabilities\UnavailabilityResource;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TeamType;
use App\Models\Unavailability;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Unavailability\Services\UnavailabilityConflictService;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UnavailabilityModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_church_unavailabilities_default_to_current_and_upcoming_with_a_past_dates_filter(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00'));
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'unavailability');
        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        app(CurrentChurch::class)->set($church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);
        $past = Unavailability::factory()->create(['church_id' => $church->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-27']);
        $current = Unavailability::factory()->create(['church_id' => $church->id, 'starts_on' => '2026-10-25', 'ends_on' => '2026-10-28']);
        $upcoming = Unavailability::factory()->create(['church_id' => $church->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02']);
        $foreign = Unavailability::factory()->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-02']);
        $this->actingAs($admin);

        Livewire::test(ListUnavailabilities::class)
            ->assertSee('Unavailabilities')
            ->assertCanSeeTableRecords([$current, $upcoming])
            ->assertCanNotSeeTableRecords([$past, $foreign])
            ->filterTable('period', 'past')
            ->assertCanSeeTableRecords([$past])
            ->assertCanNotSeeTableRecords([$current, $upcoming, $foreign])
            ->filterTable('period', 'all')
            ->assertCanSeeTableRecords([$past, $current, $upcoming])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_personal_dates_default_to_current_and_upcoming_and_keep_history_separate(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00'));
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'unavailability');
        $member = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($member, 'Member');
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);
        $past = Unavailability::factory()->create(['church_id' => $church->id, 'user_id' => $member->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-27']);
        $current = Unavailability::factory()->create(['church_id' => $church->id, 'user_id' => $member->id, 'starts_on' => '2026-10-25', 'ends_on' => '2026-10-28']);
        $upcoming = Unavailability::factory()->create(['church_id' => $church->id, 'user_id' => $member->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02']);
        $other = Unavailability::factory()->create(['church_id' => $church->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02']);
        $this->actingAs($member);

        Livewire::test(MyUnavailability::class)
            ->assertCanSeeTableRecords([$current, $upcoming])
            ->assertCanNotSeeTableRecords([$past, $other])
            ->filterTable('period', 'past')
            ->assertCanSeeTableRecords([$past])
            ->assertCanNotSeeTableRecords([$current, $upcoming, $other])
            ->filterTable('period', 'all')
            ->assertCanSeeTableRecords([$past, $current, $upcoming])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_member_can_create_own_unavailability_from_profile_menu_page(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'unavailability');

        $member = User::factory()->create([
            'church_id' => $church->id,
            'display_name' => 'Alex Member',
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($member, 'Member');

        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $this->actingAs($member);

        Livewire::test(MyUnavailability::class)
            ->callTableAction('create', data: [
                'starts_on' => '2026-11-01',
                'ends_on' => '2026-11-07',
                'note' => 'Family trip',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('unavailabilities', [
            'church_id' => $church->id,
            'user_id' => $member->id,
            'team_id' => null,
            'note' => 'Family trip',
        ]);
    }

    public function test_member_does_not_see_unavailability_in_sidebar_nav(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'unavailability');

        $member = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($member, 'Member');
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $this->actingAs($member);

        $this->assertFalse(UnavailabilityResource::shouldRegisterNavigation());
        $this->assertTrue(MyUnavailability::canAccess());
    }

    public function test_admin_can_access_church_unavailability_via_panel_nav(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'unavailability');

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $this->actingAs($admin);

        // Filament resource nav is disabled; PanelNavigation owns the item.
        $this->assertFalse(UnavailabilityResource::shouldRegisterNavigation());
        $this->assertTrue(UnavailabilityResource::canViewAny());
        $this->assertTrue(MyUnavailability::canAccess());
    }

    public function test_member_can_edit_and_delete_own_unavailability_on_profile_page(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'unavailability');

        $member = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($member, 'Member');
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $record = Unavailability::factory()->create([
            'church_id' => $church->id,
            'user_id' => $member->id,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-07',
            'note' => 'Old note',
        ]);

        $this->actingAs($member);

        Livewire::test(MyUnavailability::class)
            ->callTableAction('edit', $record, data: [
                'starts_on' => '2026-11-02',
                'ends_on' => '2026-11-08',
                'note' => 'Updated trip',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('unavailabilities', [
            'id' => $record->id,
            'note' => 'Updated trip',
        ]);

        Livewire::test(MyUnavailability::class)
            ->callTableAction('delete', $record);

        $this->assertDatabaseMissing('unavailabilities', ['id' => $record->id]);
    }

    public function test_team_leader_can_manage_team_unavailability(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'unavailability');
        app(ChurchModuleManager::class)->enable($church, 'teams');

        $type = TeamType::factory()->create(['church_id' => $church->id]);
        $leader = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($leader, 'Member');

        $team = Team::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
            'name' => 'Choir',
        ]);
        $team->syncRoster([$leader->id], [$leader->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);
        $this->actingAs($leader);

        Livewire::test(UnavailabilitiesRelationManager::class, [
            'ownerRecord' => $team,
            'pageClass' => ViewTeam::class,
        ])
            ->callTableAction('addUnavailability', data: [
                'starts_on' => '2026-12-01',
                'ends_on' => '2026-12-05',
                'note' => 'Retreat',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('unavailabilities', [
            'church_id' => $church->id,
            'team_id' => $team->id,
            'user_id' => null,
            'note' => 'Retreat',
        ]);
    }

    public function test_team_member_sees_team_unavailability_but_cannot_change_it(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'unavailability');
        app(ChurchModuleManager::class)->enable($church, 'teams');

        $leader = User::factory()->create(['church_id' => $church->id]);
        $member = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($member, 'Member');

        $team = Team::factory()->create(['church_id' => $church->id]);
        $team->syncRoster([$leader->id, $member->id], [$leader->id]);
        $retreat = Unavailability::factory()->create([
            'church_id' => $church->id,
            'team_id' => $team->id,
            'user_id' => null,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);
        $this->actingAs($member);

        Livewire::test(UnavailabilitiesRelationManager::class, [
            'ownerRecord' => $team,
            'pageClass' => ViewTeam::class,
        ])
            ->assertCanSeeTableRecords([$retreat])
            ->assertTableActionHidden('addUnavailability');

        $this->assertFalse($member->can('update', $retreat));
        $this->assertFalse($member->can('delete', $retreat));
    }

    public function test_unavailability_nav_hidden_when_module_disabled(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $this->actingAs($admin)
            ->get('/admin/unavailabilities')
            ->assertForbidden();
    }

    public function test_planner_sees_conflict_when_assignee_unavailable(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'unavailability');

        $assignee = User::factory()->create([
            'church_id' => $church->id,
            'display_name' => 'Jamie Away',
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($assignee, 'Member');

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sunday Service',
            'starts_at' => '2026-11-05 10:00:00',
        ]);

        Unavailability::factory()->create([
            'church_id' => $church->id,
            'user_id' => $assignee->id,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-10',
        ]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'name' => 'Welcome',
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $assignee->id,
            'name' => 'Welcome',
            'sort_order' => 1,
        ]);

        $messages = app(UnavailabilityConflictService::class)
            ->warningMessagesForEvent($event->fresh(['tasks.assignedUser', 'tasks.assignedTeam', 'church']));

        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('Jamie Away', $messages[0]);
        $this->assertStringContainsString('Welcome', $messages[0]);
        $this->assertStringContainsString('unavailable', $messages[0]);
    }

    public function test_planner_sees_conflict_when_assigned_team_unavailable(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'unavailability');
        app(ChurchModuleManager::class)->enable($church, 'teams');

        $type = TeamType::factory()->create(['church_id' => $church->id]);
        $team = Team::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
            'name' => 'Band',
        ]);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'starts_at' => '2026-11-05 10:00:00',
        ]);

        Unavailability::factory()->forTeam($team)->create([
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-10',
        ]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'name' => 'Music',
            'team_type_id' => $type->id,
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_team_id' => $team->id,
            'name' => 'Music',
        ]);

        $messages = app(UnavailabilityConflictService::class)
            ->warningMessagesForEvent($event->fresh(['tasks.assignedUser', 'tasks.assignedTeam', 'church']));

        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('Band', $messages[0]);
        $this->assertStringContainsString('unavailable', $messages[0]);
    }

    public function test_no_conflict_when_unavailability_outside_event(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'unavailability');

        $assignee = User::factory()->create(['church_id' => $church->id]);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'starts_at' => '2026-11-05 10:00:00',
        ]);

        Unavailability::factory()->create([
            'church_id' => $church->id,
            'user_id' => $assignee->id,
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-10',
        ]);

        $template = TaskTemplate::factory()->create(['church_id' => $church->id]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $assignee->id,
        ]);

        $this->assertSame(
            [],
            app(UnavailabilityConflictService::class)->warningMessagesForEvent(
                $event->fresh(['tasks.assignedUser', 'tasks.assignedTeam', 'church'])
            )
        );
    }

    public function test_conflicts_ignored_when_module_disabled(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $assignee = User::factory()->create(['church_id' => $church->id]);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'starts_at' => '2026-11-05 10:00:00',
        ]);

        Unavailability::factory()->create([
            'church_id' => $church->id,
            'user_id' => $assignee->id,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-10',
        ]);

        $template = TaskTemplate::factory()->create(['church_id' => $church->id]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $assignee->id,
        ]);

        $this->assertSame(
            [],
            app(UnavailabilityConflictService::class)->warningMessagesForEvent(
                $event->fresh(['tasks.assignedUser', 'tasks.assignedTeam', 'church'])
            )
        );
    }
}
