<?php

namespace Tests\Feature\Security;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Songs\Pages\ListSongs;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TwoChurchFixture;
use Tests\TestCase;

/**
 * "Delete selected" must apply each record's delete policy, not only list access.
 */
class BulkDeleteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private TwoChurchFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = TwoChurchFixture::create($this);
    }

    public function test_member_cannot_bulk_delete_songs(): void
    {
        $this->actAsChurchA($this->fixture->memberA);

        Livewire::test(ListSongs::class)->callTableBulkAction('delete', [$this->fixture->songA]);

        $this->assertModelExists($this->fixture->songA);
    }

    public function test_event_planner_cannot_bulk_delete_the_church_admin(): void
    {
        $planner = User::factory()->create(['church_id' => $this->fixture->churchA->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($planner, 'Event Planner');
        $this->actAsChurchA($planner);

        Livewire::test(ListUsers::class)->callTableBulkAction('delete', [$this->fixture->adminA, $planner]);

        $this->assertModelExists($this->fixture->adminA);
        $this->assertModelExists($planner);
    }

    public function test_church_admin_bulk_deletes_songs(): void
    {
        $this->actAsChurchA($this->fixture->adminA);

        Livewire::test(ListSongs::class)->callTableBulkAction('delete', [$this->fixture->songA]);

        $this->assertModelMissing($this->fixture->songA);
    }

    private function actAsChurchA(User $user): void
    {
        app(CurrentChurch::class)->set($this->fixture->churchA);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->fixture->churchA->id);
        $this->actingAs($user);
    }
}
