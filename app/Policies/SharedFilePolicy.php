<?php

namespace App\Policies;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Permissions\EnsuresChurchPermissionTeam;
use App\Models\SharedFile;
use App\Models\Team;
use App\Models\User;

class SharedFilePolicy
{
    use EnsuresChurchPermissionTeam;

    public function viewAny(User $user): bool
    {
        return $this->moduleAllowed($user)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can('file_sharing.view') || $user->can('file_sharing.manage')
            );
    }

    public function view(User $user, SharedFile $sharedFile): bool
    {
        return $this->sameChurch($user, $sharedFile)
            && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->moduleAllowed($user)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can('file_sharing.manage')
                    || $user->can('teams.manage')
                    || $user->leadsAnyTeam()
            );
    }

    public function update(User $user, SharedFile $sharedFile): bool
    {
        return $this->sameChurch($user, $sharedFile)
            && $this->moduleAllowed($user)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can('file_sharing.manage')
            );
    }

    public function delete(User $user, SharedFile $sharedFile): bool
    {
        return $this->update($user, $sharedFile);
    }

    public function manageForTeam(User $user, Team $team): bool
    {
        if (! $this->moduleAllowed($user)
            || $user->church_id === null
            || (int) $user->church_id !== (int) $team->church_id) {
            return false;
        }

        return $this->withChurchPermissions(
            $user,
            function () use ($user, $team) {
                if ($user->can('file_sharing.manage') || $user->can('teams.manage')) {
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

        return app(ChurchModuleManager::class)->isEnabled($user->church, 'file_sharing');
    }

    protected function sameChurch(User $user, SharedFile $sharedFile): bool
    {
        return $user->church_id !== null
            && (int) $user->church_id === (int) $sharedFile->church_id;
    }
}
