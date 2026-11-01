<?php

namespace Database\Seeders;

use App\Core\Permissions\PermissionRegistry;
use App\Models\Church;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissionNames = app(PermissionRegistry::class)->all();

        if ($permissionNames === []) {
            throw new \RuntimeException('PermissionRegistry is empty during seeding; module providers did not register permissions.');
        }

        foreach ($permissionNames as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Also register core permissions from ModuleServiceProvider boot order:
        // Events module + core are already on the registry when providers have booted.

        foreach (Church::query()->cursor() as $church) {
            $this->seedChurchRoles($church);
        }
    }

    public static function seedForChurch(Church $church): void
    {
        (new self)->seedChurchRoles($church);
    }

    protected function seedChurchRoles(Church $church): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $admin = Role::findOrCreate('Church Admin', 'web');
        $admin->syncPermissions($this->existingPermissions([
            'church.view',
            'church.update',
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'roles.view',
            'roles.manage',
            'modules.manage',
            'events.view',
            'events.create',
            'events.update',
            'events.delete',
            'task_templates.view',
            'task_templates.manage',
            'messaging.view',
            'messaging.templates.manage',
            'messaging.settings.manage',
            'messaging.send',
            'unavailability.view',
            'unavailability.manage_own',
            'unavailability.manage',
            'teams.view',
            'teams.manage',
            'pictures.view',
            'pictures.manage',
            'file_sharing.view',
            'file_sharing.manage',
            'bible_verses.view',
            'bible_verses.manage',
            'song_list.view',
            'song_list.manage',
            'song_statistics.view',
            'abilities.view_own',
            'abilities.edit_own',
            'abilities.manage',
        ]));

        $planner = Role::findOrCreate('Event Planner', 'web');
        $planner->syncPermissions($this->existingPermissions([
            'events.view',
            'events.create',
            'events.update',
            'events.delete',
            'task_templates.view',
            'messaging.send',
            'unavailability.view',
            'unavailability.manage_own',
            'teams.view',
            'pictures.view',
            'file_sharing.view',
            'bible_verses.view',
            'song_list.view',
            'song_statistics.view',
            'abilities.view_own',
            'abilities.manage',
        ]));

        $member = Role::findOrCreate('Member', 'web');
        $member->syncPermissions($this->existingPermissions([
            'events.view',
            'task_templates.view',
            'unavailability.view',
            'unavailability.manage_own',
            'pictures.view',
            'file_sharing.view',
            'bible_verses.view',
            'song_list.view',
            'abilities.view_own',
            'abilities.edit_own',
        ]));
    }

    /**
     * @param  list<string>  $names
     * @return list<Permission>
     */
    protected function existingPermissions(array $names): array
    {
        return array_map(
            fn (string $name): Permission => Permission::findOrCreate($name, 'web'),
            $names,
        );
    }

    public static function assignDefaultRoles(User $user, string $roleName = 'Church Admin'): void
    {
        if (! $user->church_id) {
            return;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);
        $user->assignRole($roleName);
    }
}
