<?php

namespace Modules\Teams\Providers;

use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleRegistry;
use App\Core\Permissions\PermissionRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Teams\Services\TeamMessageService;
use Modules\Teams\Services\TeamRosterService;

class TeamsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TeamRosterService::class);
        $this->app->singleton(TeamMessageService::class);
    }

    public function boot(ModuleRegistry $modules, PermissionRegistry $permissions): void
    {
        $modules->register(new ModuleDefinition(
            key: 'teams',
            name: 'Teams',
            description: 'Group people into teams and assign a team to an activity. Small churches can skip this and assign a person instead.',
            required: false,
        ));

        $permissions->register('teams', [
            'teams.view',
            'teams.manage',
        ]);
    }
}
