<?php

namespace App\Policies;

use App\Core\Permissions\EnsuresChurchPermissionTeam;
use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    use EnsuresChurchPermissionTeam;

    public function viewAny(User $user): bool
    {
        return $this->withChurchPermissions(
            $user,
            fn () => $user->can('events.view')
        );
    }

    public function view(User $user, Event $event): bool
    {
        return $this->sameChurch($user, $event)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can($event->is_template ? 'service_templates.use' : 'events.view')
            );
    }

    public function create(User $user): bool
    {
        return $this->withChurchPermissions(
            $user,
            fn () => $user->can('events.create')
        );
    }

    public function update(User $user, Event $event): bool
    {
        return $this->sameChurch($user, $event)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can($event->is_template ? 'service_templates.manage' : 'events.update')
            );
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->sameChurch($user, $event)
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can($event->is_template ? 'service_templates.manage' : 'events.delete')
            );
    }

    /**
     * See the service template list and create services from a template.
     */
    public function useTemplates(User $user): bool
    {
        return $this->withChurchPermissions(
            $user,
            fn () => $user->can('service_templates.use') && $user->can('events.create')
        );
    }

    /**
     * Save a service as a template.
     */
    public function saveAsTemplate(User $user, Event $event): bool
    {
        return $this->sameChurch($user, $event)
            && ! $event->is_template
            && $this->withChurchPermissions(
                $user,
                fn () => $user->can('service_templates.manage')
            );
    }

    protected function sameChurch(User $user, Event $event): bool
    {
        return $user->church_id !== null
            && (int) $user->church_id === (int) $event->church_id;
    }
}
