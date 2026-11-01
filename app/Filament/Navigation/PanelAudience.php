<?php

namespace App\Filament\Navigation;

use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * Answers "what kind of user is this?" from permissions, never from role names.
 */
class PanelAudience
{
    public function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    /**
     * A planner edits services or leads a team; everyone else is a volunteer with personal commitments.
     */
    public function isPlanner(): bool
    {
        return $this->hasAnyChurchPermission(['events.update'])
            || $this->user()?->leadsAnyTeam() === true;
    }

    /**
     * @param  list<string>  $permissions
     */
    public function hasAnyChurchPermission(array $permissions): bool
    {
        $user = $this->user();

        if ($user === null || $user->church_id === null) {
            return false;
        }

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($user->church_id);

        try {
            foreach ($permissions as $permission) {
                if ($user->can($permission)) {
                    return true;
                }
            }

            return false;
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }
}
