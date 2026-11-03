<?php

namespace Tests\Feature;

use App\Core\Modules\ChurchModuleManager;
use App\Filament\Pages\ManageModules;
use App\Models\Church;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class DemoModuleLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_locked_messaging_module_counts_as_off_in_demo_mode_even_when_stored_as_on(): void
    {
        $church = Church::factory()->create();
        $modules = app(ChurchModuleManager::class);
        $modules->enable($church, 'messaging_sms');

        config(['demo.enabled' => true]);

        $this->assertFalse($modules->isEnabled($church, 'messaging_sms'));
    }

    public function test_ntfy_stays_available_in_demo_mode(): void
    {
        config(['demo.enabled' => true]);
        $church = Church::factory()->create();
        $modules = app(ChurchModuleManager::class);

        $modules->enable($church, 'messaging_ntfy');

        $this->assertTrue($modules->isEnabled($church, 'messaging_ntfy'));
    }

    public function test_enabling_a_locked_module_in_demo_mode_is_refused(): void
    {
        config(['demo.enabled' => true]);
        $church = Church::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(ChurchModuleManager::class)->enable($church, 'messaging');
    }

    public function test_saving_the_modules_page_in_demo_mode_leaves_locked_modules_off(): void
    {
        config(['demo.enabled' => true]);
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $this->actingAs($admin);
        Livewire::test(ManageModules::class)
            ->fillForm(['module_messaging_whatsapp' => true, 'module_teams' => true])
            ->call('save');

        $this->assertDatabaseMissing('church_modules', ['church_id' => $church->id, 'module_key' => 'messaging_whatsapp', 'enabled' => true]);
        $this->assertDatabaseHas('church_modules', ['church_id' => $church->id, 'module_key' => 'teams', 'enabled' => true]);
    }

    public function test_messaging_modules_work_normally_outside_demo_mode(): void
    {
        $church = Church::factory()->create();
        $modules = app(ChurchModuleManager::class);

        $modules->enable($church, 'messaging_sms');

        $this->assertTrue($modules->isEnabled($church, 'messaging_sms'));
    }

    public function test_demo_mode_forces_the_system_mailer_to_the_log(): void
    {
        config(['demo.enabled' => true, 'mail.default' => 'smtp']);

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('log', config('mail.default'));
    }
}
