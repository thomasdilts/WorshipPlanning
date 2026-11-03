<?php

namespace App\Models;

use App\Services\Demo\DemoSnapshotBuilder;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class Role extends SpatieRole
{
    protected static function booted(): void
    {
        static::saving(function (Role $role): void {
            if ($role->isDemoLocked() && $role->isDirty('name')) {
                $role->name = $role->getOriginal('name');
            }
        });
    }

    /**
     * Refused before Spatie's own delete listener runs, which would strip the role's permissions first.
     */
    public function delete(): ?bool
    {
        if ($this->isDemoLocked()) {
            return false;
        }

        return parent::delete();
    }

    /**
     * A built-in role held by the shared demo logins: its name, permissions and existence can't change.
     */
    public function isDemoLocked(): bool
    {
        return config('demo.enabled')
            && $this->exists
            && in_array($this->getOriginal('name'), array_column(DemoSnapshotBuilder::LOGINS, 'role'), true);
    }

    public function scopeForCurrentChurch(Builder $query): Builder
    {
        $registrar = app(PermissionRegistrar::class);
        $churchId = $registrar->getPermissionsTeamId()
            ?? auth()->user()?->church_id;

        if ($churchId === null) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(
            $query->getModel()->qualifyColumn($registrar->teamsKey),
            $churchId
        );
    }

    public function churchId(): ?int
    {
        $key = app(PermissionRegistrar::class)->teamsKey;
        $value = $this->getAttribute($key);

        return $value !== null ? (int) $value : null;
    }
}
