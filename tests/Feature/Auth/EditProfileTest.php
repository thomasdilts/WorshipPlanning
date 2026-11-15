<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\EditProfile;
use App\Models\Church;
use App\Models\User;
use Database\Seeders\LanguagesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class EditProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_open_and_update_their_profile(): void
    {
        $this->seed(LanguagesSeeder::class);
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create([
            'church_id' => $church->id,
            'name' => 'Old Name',
            'display_name' => 'Old',
            'email' => 'old@example.test',
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, 'Event Planner');

        $this->actingAs($user)
            ->get('/admin/profile')
            ->assertOk()
            ->assertSee('avatarEditor', escape: false)
            ->assertSee('form.avatar_path', escape: false)
            ->assertSee('Choose picture')
            ->assertSee('Are you sure you want to delete this profile picture?');

        Livewire::test(EditProfile::class)
            ->fillForm([
                'name' => 'New Name',
                'display_name' => 'Newbie',
                'email' => 'old@example.test',
                'language_id' => $user->language_id,
                'message_preference' => $user->message_preference->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('New Name', $user->name);
        $this->assertSame('Newbie', $user->display_name);
    }

    public function test_picture_from_the_editor_is_saved_and_can_be_removed_again(): void
    {
        Storage::fake('public');
        $this->seed(LanguagesSeeder::class);
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, 'Event Planner');
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->set('data.avatar_path.new-picture', UploadedFile::fake()->image('profile-picture.jpg', 400, 400))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertDispatched('refresh-topbar');

        $path = $user->fresh()->avatar_path;
        $this->assertStringStartsWith($user->avatarDirectory().'/', $path);
        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('public')->assertExists($path);

        $page = Livewire::test(EditProfile::class);
        $storedKey = array_key_first($page->get('data.avatar_path'));

        $page->call('callSchemaComponentMethod', 'form.avatar_path', 'removeUploadedFile', ['fileKey' => $storedKey])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }
}
