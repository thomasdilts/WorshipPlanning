<?php

namespace App\Policies;

use App\Core\Permissions\EnsuresChurchPermissionTeam;
use App\Models\EventTask;
use App\Models\User;
use Modules\Abilities\Services\UserAbilities;

/**
 * Only the self-assignment rule lives here: Filament allows every ability this policy does not define,
 * so editing tasks stays governed by {@see EventPolicy::update()}.
 */
class EventTaskPolicy
{
    use EnsuresChurchPermissionTeam;

    /**
     * Someone who may not edit the service, but may put themself on a task that has no person yet,
     * then change the details of a task they are on. Planners who can edit the service get nothing extra.
     */
    public function selfAssign(User $user, EventTask $task): bool
    {
        $task->loadMissing(['event', 'template']);
        $event = $task->event;
        $template = $task->template;

        if ($event === null || $template === null || $event->is_template) {
            return false;
        }

        if ($user->church_id === null || (int) $user->church_id !== (int) $event->church_id) {
            return false;
        }

        $mayOnlyAssignSelf = $this->withChurchPermissions(
            $user,
            fn (): bool => $user->can('events.assign_self') && ! $user->can('events.update'),
        );

        if (! $mayOnlyAssignSelf || ! $template->usage('person')->isUsed()) {
            return false;
        }

        if (filled($task->assigned_user_id)) {
            return (int) $task->assigned_user_id === (int) $user->id;
        }

        return ! $task->hasGuestPerson()
            && app(UserAbilities::class)->userCanPerform($user, $template);
    }
}
