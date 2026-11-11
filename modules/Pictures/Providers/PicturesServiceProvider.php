<?php

namespace Modules\Pictures\Providers;

use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleRegistry;
use App\Core\Permissions\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

class PicturesServiceProvider extends ServiceProvider
{
    public function boot(ModuleRegistry $modules, PermissionRegistry $permissions): void
    {
        $modules->register(new ModuleDefinition(
            key: 'pictures',
            name: 'Pictures',
            description: 'Upload pictures and attach them to tasks in a service. Only turn this on if you will keep the library up to date.',
            required: false,
        ));

        $permissions->register('pictures', [
            'pictures.view',
            'pictures.manage',
        ]);
    }
}
