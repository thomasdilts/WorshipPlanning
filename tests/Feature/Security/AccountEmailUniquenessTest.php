<?php

namespace Tests\Feature\Security;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Pages\EditProfile;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TwoChurchFixture;
use Tests\TestCase;

/**
 * Sign-in and password reset find people by email alone, so an email that a church admin could
 * register for a second account would let them reset and take over the first one.
 */
class AccountEmailUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private TwoChurchFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = TwoChurchFixture::create($this);
    }

    public function test_church_admin_cannot_create_a_person_with_an_email_from_another_church(): void
    {
        $this->actAsAdminA();

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Shadow', 'email' => $this->fixture->adminB->email, 'send_invite' => false])
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique']);

        $this->assertSame(1, User::query()->where('email', $this->fixture->adminB->email)->count());
    }

    public function test_church_admin_cannot_create_a_person_with_the_platform_admin_email(): void
    {
        $this->actAsAdminA();

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Shadow', 'email' => $this->fixture->platformAdmin->email, 'send_invite' => false])
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique']);
    }

    public function test_person_cannot_change_their_email_to_another_churchs_account(): void
    {
        $this->actAsAdminA();

        Livewire::test(EditProfile::class)
            ->fillForm(['email' => $this->fixture->adminB->email])
            ->call('save')
            ->assertHasFormErrors(['email' => 'unique']);

        $this->assertSame('admin-a@example.test', $this->fixture->adminA->fresh()->email);
    }

    private function actAsAdminA(): void
    {
        app(CurrentChurch::class)->set($this->fixture->churchA);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->fixture->churchA->id);
        $this->actingAs($this->fixture->adminA);
    }
}
