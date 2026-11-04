<?php

namespace App\Providers;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Modules\ModuleRegistry;
use App\Core\Permissions\PermissionRegistry;
use App\Core\Tenancy\CurrentChurch;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentChurch::class);
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(PermissionRegistry::class);
        $this->app->singleton(ChurchModuleManager::class);

        foreach (config('modules.providers', []) as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(PermissionRegistry $permissions): void
    {
        $permissions->register('core', [
            'church.view',
            'church.update',
            'church.export',
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'roles.view',
            'roles.manage',
            'modules.manage',
        ]);
    }
}
