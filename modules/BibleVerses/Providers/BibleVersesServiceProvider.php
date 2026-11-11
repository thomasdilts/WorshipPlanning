<?php

namespace Modules\BibleVerses\Providers;

use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleRegistry;
use App\Core\Permissions\PermissionRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\BibleVerses\Services\ZamarBibleClient;

class BibleVersesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ZamarBibleClient::class);
    }

    public function boot(ModuleRegistry $modules, PermissionRegistry $permissions): void
    {
        $modules->register(new ModuleDefinition(
            key: 'bible_verses',
            name: 'Bible verses',
            description: 'Attach Bible passages to tasks and load the verse text automatically.',
            required: false,
        ));

        $permissions->register('bible_verses', [
            'bible_verses.view',
            'bible_verses.manage',
        ]);
    }
}
