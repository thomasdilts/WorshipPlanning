<?php

namespace Modules\FileSharing\Providers;

use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleRegistry;
use App\Core\Permissions\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

class FileSharingServiceProvider extends ServiceProvider
{
    public function boot(ModuleRegistry $modules, PermissionRegistry $permissions): void
    {
        $modules->register(new ModuleDefinition(
            key: 'file_sharing',
            name: 'File sharing',
            description: 'Upload files for the church or a team and attach them to tasks. Skip if you already share files elsewhere.',
            required: false,
        ));

        $permissions->register('file_sharing', [
            'file_sharing.view',
            'file_sharing.manage',
        ]);
    }
}
