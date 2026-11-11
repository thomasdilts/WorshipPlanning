<?php

namespace Modules\Abilities\Providers;

use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleRegistry;
use App\Core\Permissions\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

class AbilitiesServiceProvider extends ServiceProvider
{
    public function boot(ModuleRegistry $modules, PermissionRegistry $permissions): void
    {
        $modules->register(new ModuleDefinition(
            key: 'abilities',
            name: 'Abilities',
            description: 'Limit who can be picked for each task type. Useful with larger rosters; needs careful setup.',
            required: false,
        ));

        $permissions->register('abilities', [
            'abilities.view_own',
            'abilities.edit_own',
            'abilities.manage',
        ]);
    }
}
