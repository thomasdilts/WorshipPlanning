<?php

namespace Tests\Feature;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\UserProfilePreview;
use App\Models\Church;
use App\Models\User;
use App\Support\UserAvatar;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UserProfilePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_eye_opens_authorized_person_information_without_redirecting(): void
    {
        [$church] = $this->signInAsAdmin();
        $person = User::factory()->create([
            'church_id' => $church->id,
            'name' => 'Anna Lindqvist',
            'display_name' => 'Anna',
            'email' => 'anna@example.test',
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($person, 'Member');

        $component = Livewire::test(UserProfilePreview::class)
            ->dispatch('show-user-profile', userId: $person->id)
            ->assertActionMounted('viewUserProfile')
            ->assertNoRedirect();

        $preview = $component->instance();
        $content = $preview->getSchema($preview->getMountedActionSchemaName())->toHtml();

        $this->assertSame('Anna', $component->instance()->getMountedAction()->getModalHeading());
        $this->assertStringContainsString('Anna Lindqvist', $content);
        $this->assertStringContainsString('anna@example.test', $content);
        $this->assertStringContainsString('Member', $content);
        $this->assertStringContainsString('show-user-profile', UserAvatar::html($person)->toHtml());
        $this->assertStringContainsString('aria-haspopup="dialog"', UserAvatar::html($person)->toHtml());
    }

    public function test_preview_rejects_a_person_from_another_church(): void
    {
        $this->signInAsAdmin();
        $person = User::factory()->create(['church_id' => Church::factory()->create()->id]);

        Livewire::test(UserProfilePreview::class)
            ->dispatch('show-user-profile', userId: $person->id)
            ->assertNotFound();
    }

    public function test_preview_rejects_missing_people(): void
    {
        [$church] = $this->signInAsAdmin();
        $person = User::factory()->create(['church_id' => $church->id]);
        $personId = $person->id;
        $person->delete();

        Livewire::test(UserProfilePreview::class)
            ->dispatch('show-user-profile', userId: $personId)
            ->assertNotFound();
    }

    #[TestWith(['Member'])]
    public function test_preview_requires_permission_even_when_the_action_is_called_directly(string $role): void
    {
        [$church] = $this->signInAsAdmin();
        $viewer = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($viewer, $role);
        $person = User::factory()->create(['church_id' => $church->id]);
        $this->actingAs($viewer);

        Livewire::test(UserProfilePreview::class)
            ->mountAction('viewUserProfile', arguments: ['userId' => $person->id])
            ->assertForbidden();

        $this->assertStringNotContainsString('wp-user-profile-link', UserAvatar::html($person)->toHtml());
    }

    public function test_preview_escapes_person_information(): void
    {
        [$church] = $this->signInAsAdmin();
        $person = User::factory()->create([
            'church_id' => $church->id,
            'name' => '<script>alert("profile")</script>',
            'display_name' => 'Visitor',
        ]);

        $component = Livewire::test(UserProfilePreview::class)
            ->dispatch('show-user-profile', userId: $person->id)
            ->assertActionMounted('viewUserProfile');

        $preview = $component->instance();
        $content = $preview->getSchema($preview->getMountedActionSchemaName())->toHtml();

        $this->assertStringContainsString('&lt;script&gt;', $content);
        $this->assertStringNotContainsString($person->name, $content);
    }

    /** @return array{Church, User} */
    private function signInAsAdmin(): array
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        app(CurrentChurch::class)->set($church);
        $this->actingAs($admin);

        return [$church, $admin];
    }
}
