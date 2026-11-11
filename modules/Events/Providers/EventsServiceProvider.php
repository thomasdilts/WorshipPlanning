<?php

namespace Modules\Events\Providers;

use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleRegistry;
use App\Core\Permissions\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

class EventsServiceProvider extends ServiceProvider
{
    public function boot(ModuleRegistry $modules, PermissionRegistry $permissions): void
    {
        $modules->register(new ModuleDefinition(
            key: 'events',
            name: 'Events',
            description: 'Plan services and their order of tasks. Always on.',
            required: true,
        ));

        $permissions->register('events', [
            'events.view',
            'events.create',
            'events.update',
            'events.delete',
            'events.tasks_by_event',
            'task_templates.view',
            'task_templates.manage',
            'service_templates.use',
            'service_templates.manage',
        ]);
    }
}
