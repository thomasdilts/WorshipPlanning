<?php

namespace Tests\Feature\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Church;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');
    }

    public function test_admin_can_open_roles_index(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/roles')
            ->assertOk();
    }

    public function test_planner_cannot_manage_roles(): void
    {
        $planner = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($planner, 'Event Planner');

        $this->actingAs($planner)
            ->get('/admin/roles')
            ->assertForbidden();
    }

    public function test_admin_can_create_a_role_with_permissions(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'Sound Tech',
                'permissions_events' => ['events.view', 'events.update'],
                'permissions_core' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);

        $role = Role::query()->where('name', 'Sound Tech')->where('church_id', $this->church->id)->first();

        $this->assertNotNull($role);
        $this->assertTrue($role->hasPermissionTo('events.view'));
        $this->assertTrue($role->hasPermissionTo('events.update'));
        $this->assertFalse($role->hasPermissionTo('roles.manage'));
    }

    public function test_saving_role_creates_missing_registry_permissions(): void
    {
        Permission::query()->where('name', 'file_sharing.view')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertNull(Permission::query()->where('name', 'file_sharing.view')->first());

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
        $role = Role::findOrCreate('Member', 'web');

        $this->actingAs($this->admin);

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm([
                'name' => 'Member',
                'permissions_file_sharing' => ['file_sharing.view'],
                'permissions_events' => ['events.view'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotNull(Permission::query()->where('name', 'file_sharing.view')->first());

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
        $this->assertTrue($role->fresh()->hasPermissionTo('file_sharing.view'));
    }

    public function test_admin_can_assign_roles_to_a_user(): void
    {
        $this->actingAs($this->admin);

        Notification::fake();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Member',
                'display_name' => 'Member',
                'email' => 'member@example.test',
                'password' => 'Chosen-Pass-2026',
                'roles' => ['Member'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'member@example.test')->first();

        $this->assertNotNull($user);
        $this->assertSame($this->church->id, $user->church_id);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
        $this->assertTrue($user->hasRole('Member'));
        $this->assertTrue($user->can('events.view'));
        $this->assertFalse($user->can('events.create'));
    }
}
