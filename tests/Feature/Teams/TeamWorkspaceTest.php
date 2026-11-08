<?php

namespace Tests\Feature\Teams;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\Pages\ViewTeam;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TeamType;
use App\Models\User;
use App\Services\TeamWorkspaceSummary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TeamWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_summary_lists_leaders_and_upcoming_commitments(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'teams');
        app(CurrentChurch::class)->set($church);

        $type = TeamType::factory()->create(['church_id' => $church->id, 'name' => 'Music']);
        $leader = User::factory()->create([
            'church_id' => $church->id,
            'display_name' => 'Lee Leader',
        ]);
        $member = User::factory()->create(['church_id' => $church->id]);
        $team = Team::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
            'name' => 'Band',
            'description' => 'Sunday band',
        ]);
        $team->syncRoster([$leader->id, $member->id], [$leader->id]);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Morning service',
            'starts_at' => now()->addDays(3),
        ]);
        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'person_usage' => FieldUsage::NotUsed,
            'team_usage' => FieldUsage::Required,
            'team_type_id' => $type->id,
        ]);
        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_team_id' => $team->id,
            'name' => 'Music',
        ]);

        $summary = app(TeamWorkspaceSummary::class)->for($team->fresh());

        $this->assertTrue($summary['has_leader']);
        $this->assertSame(2, $summary['member_count']);
        $this->assertSame('Lee Leader', $summary['leaders'][0]['name']);
        $this->assertSame(1, $summary['upcoming_count']);
        $this->assertSame('Music', $summary['next_commitments'][0]['role']);
        $this->assertSame('Morning service', $summary['next_commitments'][0]['event']);
    }

    public function test_view_team_shows_workspace_not_disabled_form_fields(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'teams');
        app(CurrentChurch::class)->set($church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $type = TeamType::factory()->create(['church_id' => $church->id]);
        $leader = User::factory()->create([
            'church_id' => $church->id,
            'display_name' => 'Pat Lead',
        ]);
        $team = Team::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
            'name' => 'Greeters',
            'description' => 'Door welcome',
        ]);
        $team->syncRoster([$leader->id], [$leader->id]);

        $this->actingAs($admin);

        Livewire::test(ViewTeam::class, ['record' => $team->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Greeters')
            ->assertSee('Door welcome')
            ->assertSee('Pat Lead')
            ->assertSee(__('Leaders'))
            ->assertSee(__('Next commitments'))
            ->assertDontSee(__('Create types under Teams → Team types (e.g. Music team, Prayer team).'));
    }

    public function test_coordinator_my_teams_tab_filters_to_membership(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'teams');
        app(CurrentChurch::class)->set($church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $type = TeamType::factory()->create(['church_id' => $church->id]);
        $mine = Team::factory()->create(['church_id' => $church->id, 'team_type_id' => $type->id, 'name' => 'Mine']);
        $other = Team::factory()->create(['church_id' => $church->id, 'team_type_id' => $type->id, 'name' => 'Other']);
        $mine->syncRoster([$admin->id], [$admin->id]);
        $outsider = User::factory()->create(['church_id' => $church->id]);
        $other->syncRoster([$outsider->id], [$outsider->id]);

        $this->actingAs($admin);

        Livewire::test(ListTeams::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other])
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$mine, $other]);
    }

    public function test_team_without_leader_is_flagged(): void
    {
        $church = Church::factory()->create();
        app(ChurchModuleManager::class)->enable($church, 'teams');

        $type = TeamType::factory()->create(['church_id' => $church->id]);
        $team = Team::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
        ]);

        // Bypass roster service (which requires a leader) to simulate a broken team.
        $member = User::factory()->create(['church_id' => $church->id]);
        $team->members()->attach($member->id, ['is_leader' => false]);

        $summary = app(TeamWorkspaceSummary::class)->for($team->fresh(['members']));

        $this->assertFalse($summary['has_leader']);
        $this->assertSame(0, $summary['leader_count']);
    }
}
