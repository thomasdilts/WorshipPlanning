<?php

namespace Tests\Feature\Teams;

use App\Models\Church;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TeamPowersMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_team_permissions_are_removed_and_view_all_teams_leaves_default_member_roles(): void
    {
        $church = Church::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $oldPermissions = ['teams.view', 'teams.manage', 'teams.manage_members', 'teams.message'];
        $roles = [];

        foreach (['Member', 'Team Leader', 'Event Planner', 'Church Admin'] as $name) {
            $roles[$name] = Role::findOrCreate($name, 'web');
            $roles[$name]->givePermissionTo(array_map(fn (string $permission) => Permission::findOrCreate($permission, 'web'), $oldPermissions));
        }

        (require base_path('database/migrations/2026_11_01_053230_move_team_powers_from_roles_to_team_leadership.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(Permission::query()->whereIn('name', ['teams.manage_members', 'teams.message'])->exists());
        $this->assertSame(['teams.manage'], $roles['Member']->fresh()->permissions->pluck('name')->all());
        $this->assertSame(['teams.manage'], $roles['Team Leader']->fresh()->permissions->pluck('name')->sort()->values()->all());
        $this->assertEqualsCanonicalizing(['teams.view', 'teams.manage'], $roles['Event Planner']->fresh()->permissions->pluck('name')->all());
        $this->assertTrue(Role::query()->where('name', 'Team Leader')->exists());
    }
}
