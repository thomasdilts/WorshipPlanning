<?php

namespace Tests\Feature\Security;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Filament\Pages\ChurchSettings;
use App\Filament\Pages\EditProfile;
use App\Filament\Resources\Pictures\Pages\CreatePicture;
use App\Filament\Resources\SharedFiles\Pages\CreateSharedFile;
use App\Models\SharedFile;
use App\Models\User;
use Database\Seeders\LanguagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TwoChurchFixture;
use Tests\TestCase;

/**
 * Upload fields keep a path that comes from the browser. A path that is not the record's own file
 * must be refused, or saving and deleting would rewrite or remove another church's file.
 */
class UploadPathTamperingTest extends TestCase
{
    use RefreshDatabase;

    private TwoChurchFixture $fixture;

    private string $otherChurchFile;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(LanguagesSeeder::class);
        $this->fixture = TwoChurchFixture::create($this);
        $this->otherChurchFile = 'churches/'.$this->fixture->churchB->id.'/pictures/kept.png';
        Storage::disk('public')->put($this->otherChurchFile, UploadedFile::fake()->image('kept.png', 20, 20)->getContent());
    }

    public function test_profile_rejects_an_avatar_path_from_another_church(): void
    {
        $this->actAsChurchA($this->fixture->memberA);

        Livewire::test(EditProfile::class)
            ->set('data.avatar_path', ['tampered' => $this->otherChurchFile])
            ->call('save')
            ->assertHasFormErrors(['avatar_path']);

        $this->assertNull($this->fixture->memberA->fresh()->avatar_path);
        Storage::disk('public')->assertExists($this->otherChurchFile);
    }

    public function test_church_settings_reject_a_logo_path_from_another_church(): void
    {
        $this->actAsChurchA($this->fixture->adminA);

        Livewire::test(ChurchSettings::class)
            ->set('data.logo_path', ['tampered' => $this->otherChurchFile])
            ->call('save')
            ->assertHasFormErrors(['logo_path']);

        $this->assertNull($this->fixture->churchA->fresh()->logo_path);
    }

    public function test_church_settings_keep_the_current_logo(): void
    {
        $logo = 'churches/'.$this->fixture->churchA->id.'/logo/mark.png';
        Storage::disk('public')->put($logo, UploadedFile::fake()->image('mark.png', 20, 20)->getContent());
        $this->fixture->churchA->update(['logo_path' => $logo]);
        $this->actAsChurchA($this->fixture->adminA);

        Livewire::test(ChurchSettings::class)
            ->set('data.name', 'Alpha Renamed')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($logo, $this->fixture->churchA->fresh()->logo_path);
        $this->assertSame('Alpha Renamed', $this->fixture->churchA->fresh()->name);
    }

    public function test_new_picture_rejects_a_path_from_another_church(): void
    {
        app(ChurchModuleManager::class)->enable($this->fixture->churchA, 'pictures');
        $this->actAsChurchA($this->fixture->adminA);

        Livewire::test(CreatePicture::class)
            ->set('data.name', 'Borrowed')
            ->set('data.path', ['tampered' => $this->otherChurchFile])
            ->call('create')
            ->assertHasFormErrors(['path']);
    }

    public function test_shared_files_refuse_web_pages_and_accept_documents(): void
    {
        app(ChurchModuleManager::class)->enable($this->fixture->churchA, 'file_sharing');
        $this->actAsChurchA($this->fixture->adminA);

        Livewire::test(CreateSharedFile::class)
            ->fillForm(['name' => 'Page', 'path' => UploadedFile::fake()->createWithContent('notes.html', '<script>alert(1)</script>')])
            ->call('create')
            ->assertHasFormErrors(['path']);

        Livewire::test(CreateSharedFile::class)
            ->fillForm(['name' => 'Order of service', 'path' => UploadedFile::fake()->create('order.pdf', 10, 'application/pdf')])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['Order of service'], SharedFile::query()->pluck('name')->all());
    }

    private function actAsChurchA(User $user): void
    {
        app(CurrentChurch::class)->set($this->fixture->churchA);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->fixture->churchA->id);
        $this->actingAs($user);
    }
}
