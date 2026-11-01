<?php

namespace App\Policies;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Permissions\EnsuresChurchPermissionTeam;
use App\Models\Team;
use App\Models\Unavailability;
use App\Models\User;

class UnavailabilityPolicy
{
    use EnsuresChurchPermissionTeam;

    public function viewAny(User $user): bool
    {
        return $this->moduleAllowed($user)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can('unavailability.view')
                    || $user->can('unavailability.manage_own')
                    || $user->can('unavailability.manage')
            );
    }

    public function view(User $user, Unavailability $unavailability): bool
    {
        return $this->sameChurch($user, $unavailability)
            && $this->moduleAllowed($user)
            && $this->withChurchPermissions(
                $user,
                function () use ($user, $unavailability) {
                    if ($user->can('unavailability.manage') || $user->can('unavailability.view')) {
                        return true;
                    }

                    if ($unavailability->isPersonal()) {
                        return $user->can('unavailability.manage_own')
                            && (int) $unavailability->user_id === (int) $user->id;
                    }

                    $team = $unavailability->team;

                    return $team instanceof Team && $team->isMember($user);
                }
            );
    }

    public function create(User $user): bool
    {
        return $this->moduleAllowed($user)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can('unavailability.manage')
                    || $user->can('unavailability.manage_own')
                    || $user->can('teams.manage')
                    || $user->leadsAnyTeam()
            );
    }

    public function update(User $user, Unavailability $unavailability): bool
    {
        return $this->sameChurch($user, $unavailability)
            && $this->moduleAllowed($user)
            && $this->canMutate($user, $unavailability);
    }

    public function delete(User $user, Unavailability $unavailability): bool
    {
        return $this->sameChurch($user, $unavailability)
            && $this->moduleAllowed($user)
            && $this->canMutate($user, $unavailability);
    }

    protected function canMutate(User $user, Unavailability $unavailability): bool
    {
        return $this->withChurchPermissions(
            $user,
            function () use ($user, $unavailability) {
                if ($user->can('unavailability.manage')) {
                    return true;
                }

                if ($unavailability->isPersonal()) {
                    return $user->can('unavailability.manage_own')
                        && (int) $unavailability->user_id === (int) $user->id;
                }

                $team = $unavailability->team;

                if (! $team instanceof Team) {
                    return false;
                }

                if ($user->can('teams.manage')) {
                    return true;
                }

                return $team->isLeader($user);
            }
        );
    }

    protected function moduleAllowed(User $user): bool
    {
        if ($user->church_id === null || $user->church === null) {
            return false;
        }

        return app(ChurchModuleManager::class)->isEnabled($user->church, 'unavailability');
    }

    protected function sameChurch(User $user, Unavailability $unavailability): bool
    {
        return $user->church_id !== null
            && (int) $user->church_id === (int) $unavailability->church_id;
    }
}
