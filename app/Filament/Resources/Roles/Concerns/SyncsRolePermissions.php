<?php

namespace App\Filament\Resources\Roles\Concerns;

use App\Core\Permissions\PermissionRegistry;
use App\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property-read Role $record
 */
trait SyncsRolePermissions
{
    /** @var list<string> */
    protected array $pendingPermissions = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractPermissionsFromFormData(array $data): array
    {
        $selected = [];

        foreach (array_keys(app(PermissionRegistry::class)->allByModule()) as $module) {
            $key = "permissions_{$module}";
            $selected = [
                ...$selected,
                ...array_values($data[$key] ?? []),
            ];
            unset($data[$key]);
        }

        $allowed = app(PermissionRegistry::class)->all();
        $this->pendingPermissions = array_values(array_unique(array_intersect($selected, $allowed)));

        return $data;
    }

    protected function syncPendingPermissions(): void
    {
        if ($this->record->isDemoLocked()) {
            return;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId(auth()->user()?->church_id);

        // Registry can be ahead of a stale permissions table (new modules). Create
        // missing rows instead of letting Spatie throw PermissionDoesNotExist.
        $permissions = array_map(
            fn (string $name): Permission => Permission::findOrCreate($name, 'web'),
            $this->pendingPermissions,
        );

        $this->record->syncPermissions($permissions);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function permissionFormStateFromRole(): array
    {
        $rolePermissions = $this->record->permissions()->pluck('name')->all();
        $state = [];

        foreach (app(PermissionRegistry::class)->allByModule() as $module => $permissions) {
            $state["permissions_{$module}"] = array_values(array_intersect($rolePermissions, $permissions));
        }

        return $state;
    }
}
