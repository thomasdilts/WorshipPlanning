<?php

namespace Tests\Feature\Users;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Church;
use App\Models\Role;
use App\Models\TaskTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PeopleDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_show_abilities_and_can_be_filtered_by_ability_and_role_together(): void
    {
        [$church] = $this->signInAsAdmin();
        $sound = TaskTemplate::factory()->create(['church_id' => $church->id, 'name' => 'Sound', 'person_usage' => FieldUsage::Required]);
        $preacher = TaskTemplate::factory()->create(['church_id' => $church->id, 'name' => 'Preacher', 'person_usage' => FieldUsage::Optional]);
        $alice = $this->person($church, 'Alice', 'Member', $sound);
        $beth = $this->person($church, 'Beth', 'Event Planner', $sound);
        $carl = $this->person($church, 'Carl', 'Member', $preacher);
        $memberRole = $alice->roles->first();

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('abilityTemplates.name', ['Sound'], $alice)
            ->filterTable('abilities', [$sound->id])
            ->assertCanSeeTableRecords([$alice, $beth])
            ->assertCanNotSeeTableRecords([$carl])
            ->filterTable('roles', [$memberRole->id])
            ->assertCanSeeTableRecords([$alice])
            ->assertCanNotSeeTableRecords([$beth, $carl])
            ->filterTable('abilities', [$preacher->id])
            ->assertCanSeeTableRecords([$carl])
            ->assertCanNotSeeTableRecords([$alice, $beth]);
    }

    public function test_filters_are_restored_after_opening_a_profile_and_can_be_cleared(): void
    {
        [$church, $admin] = $this->signInAsAdmin();
        $sound = TaskTemplate::factory()->create(['church_id' => $church->id, 'person_usage' => FieldUsage::Required]);
        $alice = $this->person($church, 'Alice', 'Member', $sound);
        $beth = $this->person($church, 'Beth', 'Event Planner', $sound);
        $carl = User::factory()->create(['church_id' => $church->id]);

        Livewire::test(ListUsers::class)
            ->filterTable('abilities', [$sound->id])
            ->filterTable('roles', [$alice->roles->first()->id]);

        $this->get(UserResource::getUrl('view', ['record' => $alice]))->assertOk();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$alice])
            ->assertCanNotSeeTableRecords([$admin, $beth, $carl])
            ->call('resetTableFiltersForm');

        Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$admin, $alice, $beth, $carl]);
    }

    public function test_filter_options_and_results_stay_inside_the_current_church(): void
    {
        [$church, $admin] = $this->signInAsAdmin();
        $otherChurch = Church::factory()->create();
        RolesAndPermissionsSeeder::seedForChurch($otherChurch);
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);
        $foreignRole = Role::query()->where('church_id', $otherChurch->id)->first();
        $foreignAbility = TaskTemplate::factory()->create(['church_id' => $otherChurch->id, 'person_usage' => FieldUsage::Required]);
        $foreignPerson = User::factory()->create(['church_id' => $otherChurch->id]);
        $foreignPerson->abilityTemplates()->attach($foreignAbility);

        $component = Livewire::test(ListUsers::class)->assertCanNotSeeTableRecords([$foreignPerson]);

        $this->assertArrayNotHasKey($foreignAbility->id, $component->instance()->getTable()->getFilter('abilities')->getOptions());
        $this->assertArrayNotHasKey($foreignRole->id, $component->instance()->getTable()->getFilter('roles')->getOptions());

        $component->filterTable('abilities', [$foreignAbility->id])->assertCanNotSeeTableRecords([$admin, $foreignPerson]);
    }

    #[TestWith([false, true])]
    #[TestWith([true, false])]
    public function test_abilities_are_hidden_without_the_module_or_management_permission(bool $moduleEnabled, bool $canManage): void
    {
        [$church] = $this->signInAsAdmin();

        if (! $moduleEnabled) {
            app(ChurchModuleManager::class)->disable($church, 'abilities');
            auth()->user()->unsetRelation('church');
        }

        if (! $canManage) {
            $viewer = User::factory()->create(['church_id' => $church->id]);
            $viewer->givePermissionTo('users.view');
            $this->actingAs($viewer);
        }

        $component = Livewire::test(ListUsers::class)->assertTableColumnHidden('abilityTemplates.name');
        $this->assertNull($component->instance()->getTable()->getFilter('abilities'));
    }

    /** @return array{Church, User} */
    private function signInAsAdmin(): array
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($church, 'abilities');
        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        app(CurrentChurch::class)->set($church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);
        $this->actingAs($admin);

        return [$church, $admin];
    }

    private function person(Church $church, string $name, string $role, TaskTemplate $ability): User
    {
        $person = User::factory()->create(['church_id' => $church->id, 'name' => $name]);
        RolesAndPermissionsSeeder::assignDefaultRoles($person, $role);
        $person->abilityTemplates()->attach($ability);

        return $person;
    }
}
