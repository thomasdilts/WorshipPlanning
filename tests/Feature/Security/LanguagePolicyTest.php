<?php

namespace Tests\Feature\Security;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Languages\Pages\ManageLanguages;
use App\Models\Language;
use Database\Seeders\LanguagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TwoChurchFixture;
use Tests\TestCase;

/**
 * Languages are shared by every church on the server, so only the platform admin changes them.
 */
class LanguagePolicyTest extends TestCase
{
    use RefreshDatabase;

    private TwoChurchFixture $fixture;

    private Language $language;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = TwoChurchFixture::create($this);
        $this->seed(LanguagesSeeder::class);
        $this->language = Language::query()->firstOrFail();
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function abilities(): array
    {
        return [
            'platform admin' => ['platformAdmin', true, true],
            'church admin' => ['adminA', true, false],
            'member' => ['memberA', false, false],
        ];
    }

    #[DataProvider('abilities')]
    public function test_who_may_see_and_change_languages(string $person, bool $canView, bool $canChange): void
    {
        $user = $this->fixture->{$person};
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        $this->assertSame($canView, $user->can('viewAny', Language::class));
        $this->assertSame($canChange, $user->can('create', Language::class));
        $this->assertSame($canChange, $user->can('update', $this->language));
        $this->assertSame($canChange, $user->can('delete', $this->language));
    }

    public function test_church_admin_cannot_edit_a_language_from_the_page(): void
    {
        app(CurrentChurch::class)->set($this->fixture->churchA);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->fixture->churchA->id);
        $this->actingAs($this->fixture->adminA);

        Livewire::test(ManageLanguages::class)->assertTableActionHidden('edit', $this->language);
    }

    public function test_platform_admin_edits_a_language(): void
    {
        $this->actingAs($this->fixture->platformAdmin);

        Livewire::test(ManageLanguages::class)
            ->callTableAction('edit', $this->language, data: ['name_en' => 'Renamed'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Renamed', $this->language->fresh()->name_en);
    }
}
