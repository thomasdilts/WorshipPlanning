<?php

namespace App\Policies;

use App\Core\Permissions\EnsuresChurchPermissionTeam;
use App\Models\Event;
use App\Models\EventMessage;
use App\Models\User;
use Modules\Messaging\Support\MessagingAvailability;

class EventMessagePolicy
{
    use EnsuresChurchPermissionTeam;

    public function viewAny(User $user, ?Event $event = null): bool
    {
        return $this->canUseMessaging($user)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can('messaging.view') || $user->can('messaging.send')
            );
    }

    public function create(User $user): bool
    {
        return $this->canUseMessaging($user)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can('messaging.send')
            );
    }

    public function view(User $user, EventMessage $message): bool
    {
        return $this->sameChurch($user, $message)
            && $this->canUseMessaging($user)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can('messaging.view') || $user->can('messaging.send')
            );
    }

    /**
     * Only the message's author, or someone who oversees all messages, may delete it.
     */
    public function delete(User $user, EventMessage $message): bool
    {
        return $this->sameChurch($user, $message)
            && $this->canUseMessaging($user)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can('messaging.view')
                    || ($user->can('messaging.send') && (int) $message->created_by_user_id === (int) $user->id)
            );
    }

    protected function canUseMessaging(User $user): bool
    {
        if ($user->church_id === null || $user->church === null) {
            return false;
        }

        return app(MessagingAvailability::class)->anyEnabled($user->church);
    }

    protected function sameChurch(User $user, EventMessage $message): bool
    {
        return $user->church_id !== null
            && (int) $user->church_id === (int) $message->church_id;
    }
}
