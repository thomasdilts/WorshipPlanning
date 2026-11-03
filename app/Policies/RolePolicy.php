<?php

namespace App\Policies;

use App\Core\Permissions\EnsuresChurchPermissionTeam;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

class RolePolicy
{
    use EnsuresChurchPermissionTeam;

    public function viewAny(User $user): bool
    {
        return $this->withChurchPermissions(
            $user,
            fn () => $user->can('roles.view') || $user->can('roles.manage')
        );
    }

    public function view(User $user, Role $role): bool
    {
        return $this->sameChurch($user, $role)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can('roles.view') || $user->can('roles.manage')
            );
    }

    public function create(User $user): bool
    {
        return $this->withChurchPermissions(
            $user,
            fn () => $user->can('roles.manage')
        );
    }

    public function update(User $user, Role $role): bool
    {
        return $this->sameChurch($user, $role)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can('roles.manage')
            );
    }

    public function delete(User $user, Role $role): bool
    {
        return ! $role->isDemoLocked()
            && $this->sameChurch($user, $role)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can('roles.manage')
            );
    }

    protected function sameChurch(User $user, Role $role): bool
    {
        $teamKey = app(PermissionRegistrar::class)->teamsKey;

        return $user->church_id !== null
            && (int) $user->church_id === (int) $role->getAttribute($teamKey);
    }
}
