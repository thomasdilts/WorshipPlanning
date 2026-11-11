<?php

namespace Tests\Feature\Security;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TwoChurchFixture;
use Tests\TestCase;

/**
 * Choosing roles hands out their permissions, so only "Manage roles & permissions" may do it.
 */
class RoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private TwoChurchFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = TwoChurchFixture::create($this);
    }

    public function test_person_editor_without_roles_manage_cannot_give_themselves_church_admin(): void
    {
        $editor = $this->personWithCustomRole('Office helper', ['users.view', 'users.update'], 'helper@example.test');

        Livewire::test(EditUser::class, ['record' => $editor->getRouteKey()])
            ->fillForm(['roles' => ['Office helper', 'Church Admin']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Office helper'], $this->roleNames($editor));
    }

    public function test_person_creator_without_roles_manage_can_only_create_members(): void
    {
        $this->personWithCustomRole('Greeter lead', ['users.view', 'users.create'], 'lead@example.test');

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'New Person', 'email' => 'new@example.test', 'password' => 'A-Long-Pass-123', 'send_invite' => false, 'roles' => ['Church Admin']])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['Member'], $this->roleNames(User::query()->where('email', 'new@example.test')->sole()));
    }

    public function test_roles_manager_assigns_roles(): void
    {
        $this->actAsChurchA($this->fixture->adminA);

        Livewire::test(EditUser::class, ['record' => $this->fixture->memberA->getRouteKey()])
            ->fillForm(['roles' => ['Member', 'Event Planner']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(['Member', 'Event Planner'], $this->roleNames($this->fixture->memberA));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function personWithCustomRole(string $role, array $permissions, string $email): User
    {
        $this->actAsChurchA($this->fixture->adminA);
        Livewire::test(CreateRole::class)
            ->fillForm(['name' => $role, 'permissions_core' => $permissions])
            ->call('create')
            ->assertHasNoFormErrors();

        $person = User::factory()->create(['church_id' => $this->fixture->churchA->id, 'email' => $email]);
        $person->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actAsChurchA($person);

        return $person;
    }

    private function actAsChurchA(User $user): void
    {
        app(CurrentChurch::class)->set($this->fixture->churchA);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->fixture->churchA->id);
        $this->actingAs($user);
    }

    /**
     * @return list<string>
     */
    private function roleNames(User $user): array
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->fresh()->getRoleNames()->all();
    }
}
