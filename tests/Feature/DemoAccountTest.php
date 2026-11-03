<?php

namespace Tests\Feature;

use App\Filament\Pages\EditProfile;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Church;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class DemoAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_account_keeps_its_email_and_password(): void
    {
        $user = $this->demoUser();

        $user->update(['email' => 'taken-over@evil.test', 'password' => 'new-password']);

        $user->refresh();
        $this->assertSame('admin@example.com', $user->email);
        $this->assertTrue(Hash::check('demo1234', $user->password));
    }

    public function test_demo_account_can_change_its_ntfy_topic(): void
    {
        $user = $this->demoUser();

        $user->update(['ntfy_topic' => 'wp-my-own-topic']);

        $this->assertSame('wp-my-own-topic', $user->refresh()->ntfy_topic);
    }

    public function test_accounts_outside_the_demo_domain_can_change_their_password_in_demo_mode(): void
    {
        $user = $this->demoUser(['email' => 'new.person@church.test']);

        $user->update(['password' => 'new-password']);

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_example_accounts_can_change_their_password_outside_demo_mode(): void
    {
        $user = $this->demoUser();
        config(['demo.enabled' => false]);

        $user->update(['password' => 'new-password']);

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_profile_page_hides_password_and_locks_email_for_demo_accounts(): void
    {
        $user = $this->demoUser();
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->assertFormFieldDisabled('password')
            ->assertFormFieldDisabled('email')
            ->assertSee('Locked in demo');
    }

    public function test_the_four_demo_logins_cannot_be_deleted(): void
    {
        $login = $this->demoUser(['email' => 'planner@example.com']);
        $admin = User::factory()->create(['church_id' => $login->church_id, 'email' => 'admin@example.com']);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $this->assertFalse($admin->can('delete', $login));

        $login->delete();

        $this->assertModelExists($login);
        $this->assertTrue($login->refresh()->hasRole('Church Admin'));
    }

    public function test_other_demo_accounts_can_still_be_deleted(): void
    {
        $person = $this->demoUser(['email' => 'jane.doe@example.com']);
        $admin = User::factory()->create(['church_id' => $person->church_id, 'email' => 'admin@example.com']);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $this->assertTrue($admin->can('delete', $person));

        $person->delete();

        $this->assertModelMissing($person);
    }

    public function test_admin_cannot_change_the_roles_of_a_demo_login(): void
    {
        $login = $this->demoUser(['email' => 'planner@example.com']);
        $admin = User::factory()->create(['church_id' => $login->church_id, 'email' => 'admin@example.com']);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $login->getRouteKey()])
            ->assertFormFieldDisabled('roles')
            ->fillForm(['roles' => ['Member']])
            ->call('save');

        $this->assertSame(['Church Admin'], $login->refresh()->getRoleNames()->all());
    }

    public function test_built_in_roles_cannot_be_renamed_deleted_or_have_permissions_changed(): void
    {
        $admin = $this->demoUser();
        $role = Role::query()->where('church_id', $admin->church_id)->where('name', 'Church Admin')->firstOrFail();
        $permissionCount = $role->permissions()->count();
        $this->actingAs($admin);

        $this->assertFalse($admin->can('delete', $role));

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->assertFormFieldDisabled('name')
            ->call('save');
        $role->update(['name' => 'Renamed']);
        $role->delete();

        $role = Role::query()->findOrFail($role->id);
        $this->assertSame('Church Admin', $role->name);
        $this->assertSame($permissionCount, $role->permissions()->count());
    }

    public function test_roles_testers_create_can_still_be_deleted(): void
    {
        $admin = $this->demoUser();
        $role = Role::query()->create(['name' => 'Sound tech', 'guard_name' => 'web', 'church_id' => $admin->church_id]);

        $this->assertTrue($admin->can('delete', $role));
        $role->delete();

        $this->assertModelMissing($role);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function demoUser(array $attributes = []): User
    {
        config(['demo.enabled' => true]);
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create([
            'church_id' => $church->id,
            'email' => 'admin@example.com',
            'password' => Hash::make('demo1234'),
            ...$attributes,
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, 'Church Admin');

        return $user;
    }
}
