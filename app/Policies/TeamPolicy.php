<?php

namespace App\Policies;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Permissions\EnsuresChurchPermissionTeam;
use App\Models\Team;
use App\Models\User;
use Modules\Messaging\Support\MessagingAvailability;

class TeamPolicy
{
    use EnsuresChurchPermissionTeam;

    public function viewAny(User $user): bool
    {
        return $this->teamsAllowed($user)
            && $this->withChurchPermissions(
                $user,
                function () use ($user) {
                    if ($user->can('teams.manage') || $user->can('teams.view')) {
                        return true;
                    }

                    // Everyone else sees Teams only while they are on at least one team.
                    return $user->teams()
                        ->where('teams.church_id', $user->church_id)
                        ->exists();
                }
            );
    }

    public function view(User $user, Team $team): bool
    {
        if (! $this->sameChurch($user, $team) || ! $this->teamsAllowed($user)) {
            return false;
        }

        return $this->withChurchPermissions(
            $user,
            function () use ($user, $team) {
                if ($user->can('teams.manage') || $user->can('teams.view')) {
                    return true;
                }

                // Any roster member can open their team (even without teams.* permissions).
                return $team->isMember($user);
            }
        );
    }

    public function create(User $user): bool
    {
        return $this->teamsAllowed($user)
            && $this->withChurchPermissions($user, fn () => $user->can('teams.manage'));
    }

    public function update(User $user, Team $team): bool
    {
        if (! $this->sameChurch($user, $team) || ! $this->teamsAllowed($user)) {
            return false;
        }

        return $this->withChurchPermissions(
            $user,
            fn () => $user->can('teams.manage')
        );
    }

    public function delete(User $user, Team $team): bool
    {
        return $this->sameChurch($user, $team)
            && $this->teamsAllowed($user)
            && $this->withChurchPermissions($user, fn () => $user->can('teams.manage'));
    }

    public function manageMembers(User $user, Team $team): bool
    {
        if (! $this->sameChurch($user, $team) || ! $this->teamsAllowed($user)) {
            return false;
        }

        return $this->withChurchPermissions(
            $user,
            function () use ($user, $team) {
                if ($user->can('teams.manage')) {
                    return true;
                }

                return $team->isLeader($user);
            }
        );
    }

    public function message(User $user, Team $team): bool
    {
        if (! $this->sameChurch($user, $team) || ! $this->teamsAllowed($user)) {
            return false;
        }

        if (! app(MessagingAvailability::class)->anyEnabled($user->church)) {
            return false;
        }

        return $this->withChurchPermissions(
            $user,
            function () use ($user, $team) {
                if ($user->can('teams.manage')) {
                    return true;
                }

                return $team->isMember($user);
            }
        );
    }

    protected function teamsAllowed(User $user): bool
    {
        if ($user->church_id === null || $user->church === null) {
            return false;
        }

        return app(ChurchModuleManager::class)->isEnabled($user->church, 'teams');
    }

    protected function sameChurch(User $user, Team $team): bool
    {
        return $user->church_id !== null
            && (int) $user->church_id === (int) $team->church_id;
    }
}
