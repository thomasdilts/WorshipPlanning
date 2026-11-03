<?php

namespace App\Policies;

use App\Core\Permissions\EnsuresChurchPermissionTeam;
use App\Models\User;

class UserPolicy
{
    use EnsuresChurchPermissionTeam;

    public function viewAny(User $user): bool
    {
        return $this->withChurchPermissions(
            $user,
            fn () => $user->can('users.view') || $user->can('events.update')
        );
    }

    public function view(User $actor, User $subject): bool
    {
        return $this->sameChurch($actor, $subject)
            && $this->withChurchPermissions(
                $actor,
                fn () => $actor->can('users.view') || $actor->can('events.update')
            );
    }

    public function create(User $user): bool
    {
        return $this->withChurchPermissions(
            $user,
            fn () => $user->can('users.create')
        );
    }

    public function update(User $actor, User $subject): bool
    {
        return $this->sameChurch($actor, $subject)
            && $this->withChurchPermissions(
                $actor,
                fn () => $actor->can('users.update')
            );
    }

    public function delete(User $actor, User $subject): bool
    {
        if ($actor->is($subject) || $subject->isDemoLogin()) {
            return false;
        }

        return $this->sameChurch($actor, $subject)
            && $this->withChurchPermissions(
                $actor,
                fn () => $actor->can('users.delete')
            );
    }

    protected function sameChurch(User $actor, User $subject): bool
    {
        return $actor->church_id !== null
            && (int) $actor->church_id === (int) $subject->church_id;
    }
}
