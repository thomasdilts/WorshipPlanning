<?php

namespace Modules\Unavailability\Providers;

use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleRegistry;
use App\Core\Permissions\PermissionRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Unavailability\Services\UnavailabilityConflictService;

class UnavailabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UnavailabilityConflictService::class);
    }

    public function boot(ModuleRegistry $modules, PermissionRegistry $permissions): void
    {
        $modules->register(new ModuleDefinition(
            key: 'unavailability',
            name: 'Unavailable',
            description: 'People mark dates they cannot serve. Planners see a warning when assigning someone who is away.',
            required: false,
        ));

        $permissions->register('unavailability', [
            'unavailability.view',
            'unavailability.manage_own',
            'unavailability.manage',
        ]);
    }
}
