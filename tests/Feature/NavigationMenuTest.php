<?php

namespace Tests\Feature;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Filament\Pages\ChurchSettings;
use App\Filament\Pages\ManageModules;
use App\Filament\Pages\MessageHistory;
use App\Filament\Pages\MyMessages;
use App\Filament\Resources\Churches\ChurchResource;
use App\Filament\Resources\Languages\LanguageResource;
use App\Filament\Resources\Pictures\PictureResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Songs\SongResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Church;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Teams\Services\TeamRosterService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NavigationMenuTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        app(ChurchModuleManager::class)->enable($this->church, 'teams');
        app(ChurchModuleManager::class)->enable($this->church, 'song_list');
        app(ChurchModuleManager::class)->enable($this->church, 'pictures');
        app(ChurchModuleManager::class)->enable($this->church, 'messaging');

        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
    }

    public function test_team_nav_shows_for_member_without_team_permissions(): void
    {
        $user = User::factory()->create(['church_id' => $this->church->id]);
        $leader = User::factory()->create(['church_id' => $this->church->id]);

        Role::findOrCreate('Bare', 'web');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
        $user->assignRole('Bare');

        $team = Team::factory()->create(['church_id' => $this->church->id]);
        app(TeamRosterService::class)->syncRoster($team, [$leader->id, $user->id], [$leader->id]);

        $this->actingAs($user);

        $this->assertTrue(TeamResource::canViewAny());
        $this->assertTrue(TeamResource::shouldRegisterNavigation());
        $this->assertTrue($user->can('view', $team));
    }

    public function test_team_nav_hides_when_module_off_even_for_member(): void
    {
        $user = User::factory()->create(['church_id' => $this->church->id]);
        $leader = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, 'Member');

        $team = Team::factory()->create(['church_id' => $this->church->id]);
        app(TeamRosterService::class)->syncRoster($team, [$leader->id, $user->id], [$leader->id]);

        app(ChurchModuleManager::class)->disable($this->church, 'teams');

        $this->actingAs($user);

        $this->assertFalse(TeamResource::canViewAny());
        $this->assertFalse(TeamResource::shouldRegisterNavigation());
    }

    public function test_team_nav_hides_for_non_member_without_team_rights(): void
    {
        $user = User::factory()->create(['church_id' => $this->church->id]);
        Role::findOrCreate('Bare', 'web');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
        $user->assignRole('Bare');

        $this->actingAs($user);

        $this->assertFalse(TeamResource::canViewAny());
    }

    public function test_songs_nav_requires_module_and_rights(): void
    {
        $member = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($member, 'Member');

        $this->actingAs($member);
        $this->assertTrue(SongResource::shouldRegisterNavigation());

        app(ChurchModuleManager::class)->disable($this->church, 'song_list');
        $this->assertFalse(SongResource::shouldRegisterNavigation());
    }

    public function test_admin_submenu_visibility_follows_rights(): void
    {
        $admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $this->actingAs($admin);
        $this->assertTrue(UserResource::shouldRegisterNavigation());
        $this->assertTrue(RoleResource::shouldRegisterNavigation());
        $this->assertTrue(ManageModules::shouldRegisterNavigation());
        $this->assertTrue(ChurchSettings::shouldRegisterNavigation());
        $this->assertTrue(PictureResource::shouldRegisterNavigation());
        $this->assertTrue(LanguageResource::shouldRegisterNavigation());
        $this->assertTrue(MessageHistory::canAccess());

        $member = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($member, 'Member');

        $this->actingAs($member);
        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(RoleResource::canViewAny());
        $this->assertFalse(ManageModules::canAccess());
        $this->assertFalse(ChurchSettings::canAccess());
        $this->assertFalse(LanguageResource::canViewAny());
        $this->assertFalse(MessageHistory::canAccess());
        $this->assertTrue(MyMessages::canAccess());
    }

    public function test_top_level_nav_labels_and_groups(): void
    {
        $this->assertNull(TeamResource::getNavigationGroup());
        $this->assertNull(SongResource::getNavigationGroup());
        $this->assertSame(__('Admin'), UserResource::getNavigationGroup());
        $this->assertSame(__('Admin'), RoleResource::getNavigationGroup());
        $this->assertSame(__('Admin'), ManageModules::getNavigationGroup());
        $this->assertSame(__('Admin'), ChurchSettings::getNavigationGroup());
        $this->assertSame(__('Admin'), ChurchResource::getNavigationGroup());
        $this->assertSame(__('Admin'), LanguageResource::getNavigationGroup());
        $this->assertSame(__('Church'), ChurchSettings::getNavigationLabel());
        $this->assertSame(__('Churches'), ChurchResource::getNavigationLabel());
        $this->assertSame(__('Team'), TeamResource::getNavigationLabel());
        $this->assertSame(__('Songs'), SongResource::getNavigationLabel());
        $this->assertSame(__('Message history'), MessageHistory::getNavigationLabel());
    }

    public function test_platform_admin_sees_churches_nav_not_church_settings(): void
    {
        $platform = User::factory()->platformAdmin()->create();

        $this->actingAs($platform);

        $this->assertTrue(ChurchResource::shouldRegisterNavigation());
        $this->assertFalse(ChurchSettings::shouldRegisterNavigation());
        $this->assertFalse(UserResource::shouldRegisterNavigation());
    }
}
