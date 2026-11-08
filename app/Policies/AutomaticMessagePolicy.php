<?php

namespace App\Policies;

use App\Core\Permissions\EnsuresChurchPermissionTeam;
use App\Models\AutomaticMessage;
use App\Models\User;
use Modules\Messaging\Support\MessagingAvailability;

/**
 * Automatic messages are managed only by those with messaging.automatic.manage (Church Admins by default).
 */
class AutomaticMessagePolicy
{
    use EnsuresChurchPermissionTeam;

    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, AutomaticMessage $automaticMessage): bool
    {
        return $this->sameChurch($user, $automaticMessage) && $this->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, AutomaticMessage $automaticMessage): bool
    {
        return $this->sameChurch($user, $automaticMessage) && $this->canManage($user);
    }

    public function delete(User $user, AutomaticMessage $automaticMessage): bool
    {
        return $this->sameChurch($user, $automaticMessage) && $this->canManage($user);
    }

    protected function canManage(User $user): bool
    {
        if ($user->church_id === null || $user->church === null) {
            return false;
        }

        return app(MessagingAvailability::class)->anyEnabled($user->church)
            && $this->withChurchPermissions($user, fn () => $user->can('messaging.automatic.manage'));
    }

    protected function sameChurch(User $user, AutomaticMessage $automaticMessage): bool
    {
        return $user->church_id !== null
            && (int) $user->church_id === (int) $automaticMessage->church_id;
    }
}
