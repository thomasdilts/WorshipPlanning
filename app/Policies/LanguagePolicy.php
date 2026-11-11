<?php

namespace App\Policies;

use App\Core\Permissions\EnsuresChurchPermissionTeam;
use App\Models\Language;
use App\Models\User;

class LanguagePolicy
{
    use EnsuresChurchPermissionTeam;

    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin() || $this->isChurchAdmin($user);
    }

    public function view(User $user, Language $language): bool
    {
        return $this->viewAny($user);
    }

    /*
     * Languages are shared by every church on the server, so only the platform admin may change
     * them. A church admin changing a row would change it for all other churches too.
     */

    public function create(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function update(User $user, Language $language): bool
    {
        return $user->isPlatformAdmin();
    }

    public function delete(User $user, Language $language): bool
    {
        return $user->isPlatformAdmin();
    }

    protected function isChurchAdmin(User $user): bool
    {
        if ($user->church_id === null) {
            return false;
        }

        return $this->withChurchPermissions(
            $user,
            fn () => $user->can('church.update') || $user->can('modules.manage')
        );
    }
}
