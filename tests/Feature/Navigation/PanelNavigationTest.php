<?php

namespace Tests\Feature\Navigation;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Filament\Navigation\LandingDestination;
use App\Filament\Navigation\PanelNavigation;
use App\Filament\Pages\ManageModules;
use App\Filament\Pages\MyTasks;
use App\Models\Church;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Messaging\Support\MessagingAvailability;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PanelNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (['teams', 'song_list', 'unavailability'] as $module) {
            app(ChurchModuleManager::class)->enable($this->church, $module);
        }

        app(CurrentChurch::class)->set($this->church);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, $role);

        return $user;
    }

    /**
     * @return array{top: list<string>, groups: array<string, list<string>>}
     */
    private function navigationFor(User $user): array
    {
        $this->actingAs($user);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $top = [];
        $groups = [];

        foreach (app(PanelNavigation::class)->build(new NavigationBuilder)->getNavigation() as $group) {
            $labels = collect($group->getItems())
                ->filter(fn (NavigationItem $item): bool => $item->isVisible())
                ->map(fn (NavigationItem $item): string => (string) $item->getLabel())
                ->values()
                ->all();

            if ($group->getLabel() === null) {
                $top = $labels;

                continue;
            }

            $groups[(string) $group->getLabel()] = $labels;
        }

        return ['top' => $top, 'groups' => $groups];
    }

    public function test_planner_sees_tasks_by_event_services_and_my_schedule(): void
    {
        $navigation = $this->navigationFor($this->userWithRole('Event Planner'));

        $this->assertContains('Tasks by event', $navigation['top']);
        $this->assertContains('Services', $navigation['top']);
        $this->assertContains('My schedule', $navigation['top']);
        $this->assertContains('Availability', $navigation['top']);
    }

    public function test_volunteer_reaches_my_schedule_but_not_planning_or_settings(): void
    {
        $navigation = $this->navigationFor($this->userWithRole('Member'));

        $this->assertContains('My schedule', $navigation['top']);
        $this->assertContains('Availability', $navigation['top']);
        $this->assertNotContains('Tasks by event', $navigation['top']);
        $this->assertArrayNotHasKey('Settings', $navigation['groups']);
    }

    public function test_church_admin_gets_a_settings_menu(): void
    {
        $navigation = $this->navigationFor($this->userWithRole('Church Admin'));

        $this->assertArrayHasKey('Settings', $navigation['groups']);
        $this->assertContains('Roles', $navigation['groups']['Settings']);
        $this->assertContains('Activity templates', $navigation['groups']['Settings']);
        $this->assertContains('Export church data', $navigation['groups']['Settings']);
    }

    public function test_module_settings_sit_in_a_modules_sub_menu(): void
    {
        foreach ([MessagingAvailability::MODULE_EMAIL, MessagingAvailability::MODULE_SMS, MessagingAvailability::MODULE_NTFY, MessagingAvailability::MODULE_WHATSAPP, MessagingAvailability::MODULE_TELEGRAM, 'bible_verses'] as $module) {
            app(ChurchModuleManager::class)->enable($this->church, $module);
        }

        $this->actingAs($this->userWithRole('Church Admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $settings = collect(app(PanelNavigation::class)->build(new NavigationBuilder)->getNavigation())
            ->first(fn ($group): bool => $group->getLabel() === 'Settings');
        $modules = collect($settings->getItems())->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Modules');

        $this->assertSame(
            ['Module selection', 'Email settings', 'SMS settings', 'ntfy settings', 'WhatsApp settings', 'Telegram settings', 'Bible settings'],
            collect($modules->getChildItems())->map(fn (NavigationItem $item): string => (string) $item->getLabel())->all(),
        );
        $this->assertSame(ManageModules::getUrl(), $modules->getUrl());
        $this->assertNotContains('Email settings', $this->navigationFor($this->userWithRole('Church Admin'))['groups']['Settings']);
    }

    public function test_modules_sub_menu_lists_only_enabled_module_settings(): void
    {
        $this->actingAs($this->userWithRole('Church Admin'));
        $settings = collect(app(PanelNavigation::class)->build(new NavigationBuilder)->getNavigation())
            ->first(fn ($group): bool => $group->getLabel() === 'Settings');
        $modules = collect($settings->getItems())->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Modules');

        $this->assertSame(['Module selection'], collect($modules->getChildItems())->map(fn (NavigationItem $item): string => (string) $item->getLabel())->all());
    }

    public function test_tasks_by_event_follows_its_own_permission_not_role_names(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
        $role = Role::findOrCreate('Sunday Wizard', 'web');
        $role->givePermissionTo(['events.view', 'events.update']);

        $user = User::factory()->create(['church_id' => $this->church->id]);
        $user->assignRole('Sunday Wizard');

        $this->assertNotContains('Tasks by event', $this->navigationFor($user)['top']);

        $role->givePermissionTo('events.tasks_by_event');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertContains('Tasks by event', $this->navigationFor($user->fresh())['top']);
    }

    public function test_volunteer_role_with_the_permission_sees_tasks_by_event(): void
    {
        $member = $this->userWithRole('Member');
        $this->assertNotContains('Tasks by event', $this->navigationFor($member)['top']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
        Role::findByName('Member', 'web')->givePermissionTo('events.tasks_by_event');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertContains('Tasks by event', $this->navigationFor($member->fresh())['top']);
    }

    public function test_a_disabled_module_removes_its_destinations(): void
    {
        $planner = $this->userWithRole('Event Planner');
        $before = $this->navigationFor($planner);
        $this->assertContains('Songs', [...$before['top'], ...$this->flatten($before)]);

        app(ChurchModuleManager::class)->disable($this->church, 'song_list');

        $after = $this->navigationFor($planner);
        $this->assertNotContains('Songs', $after['top']);
        $this->assertNotContains('Songs', $this->flatten($after));
    }

    public function test_a_group_with_one_visible_destination_collapses_into_a_plain_item(): void
    {
        $navigation = $this->navigationFor($this->userWithRole('Member'));

        $this->assertArrayNotHasKey('Library', $navigation['groups']);
        $this->assertContains('Songs', $navigation['top']);
    }

    public function test_platform_admin_sees_churches_and_none_of_the_church_planning_entries(): void
    {
        app(CurrentChurch::class)->forget();
        $admin = User::factory()->platformAdmin()->create(['church_id' => null]);

        $navigation = $this->navigationFor($admin);

        // Languages are shared by every church, so only the platform admin manages them.
        $this->assertSame(['My schedule', 'Churches', 'Languages'], $navigation['top']);
        $this->assertSame([], $navigation['groups']);
    }

    public function test_landing_destination_follows_permissions(): void
    {
        $this->actingAs($this->userWithRole('Event Planner'));
        $this->assertSame(MyTasks::getUrl(), app(LandingDestination::class)->url());

        $this->actingAs($this->userWithRole('Member'));
        $this->assertSame(MyTasks::getUrl(), app(LandingDestination::class)->url());

        app(CurrentChurch::class)->forget();
        $this->actingAs(User::factory()->platformAdmin()->create(['church_id' => null]));
        $this->assertSame(MyTasks::getUrl(), app(LandingDestination::class)->url());
    }

    public function test_admin_route_redirects_each_audience_to_its_landing_page(): void
    {
        $this->actingAs($this->userWithRole('Church Admin'))
            ->get('/admin')
            ->assertRedirect(MyTasks::getUrl());

        $this->actingAs($this->userWithRole('Member'))
            ->get('/admin')
            ->assertRedirect(MyTasks::getUrl());
    }

    /**
     * @param  array{top: list<string>, groups: array<string, list<string>>}  $navigation
     * @return list<string>
     */
    private function flatten(array $navigation): array
    {
        return collect($navigation['groups'])->flatten()->all();
    }
}
