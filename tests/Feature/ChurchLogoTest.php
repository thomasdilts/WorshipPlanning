<?php

namespace Tests\Feature;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Pages\ChurchSettings;
use App\Models\Church;
use App\Models\Event;
use App\Models\User;
use App\Services\EventPdfExporter;
use App\Services\EventTaskRowSummary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChurchLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_church_logo(): void
    {
        Storage::fake('public');

        $church = Church::factory()->create(['name' => 'Hope Church']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        app(CurrentChurch::class)->set($church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $this->actingAs($admin);

        $file = UploadedFile::fake()->image('logo.png', 200, 80);

        Livewire::test(ChurchSettings::class)
            ->fillForm([
                'name' => 'Hope Church',
                'logo_path' => [$file],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $church->refresh();

        $this->assertNotNull($church->logo_path);
        Storage::disk('public')->assertExists($church->logo_path);
        $this->assertNotNull($church->logoUrl());
        $this->assertNotNull($church->logoAbsolutePath());
    }

    public function test_member_cannot_open_church_settings(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $member = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($member, 'Member');

        app(CurrentChurch::class)->set($church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $this->actingAs($member)
            ->get('/admin/church-settings')
            ->assertForbidden();
    }

    public function test_compact_pdf_shows_a_small_church_logo_in_the_top_right(): void
    {
        Storage::fake('public');

        $path = 'churches/1/logo/mark.png';
        Storage::disk('public')->put($path, UploadedFile::fake()->image('mark.png')->getContent());

        $church = Church::factory()->create([
            'name' => 'Hope Church',
            'logo_path' => $path,
        ]);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sunday Service',
        ]);

        $event->load('church');

        $html = View::make('pdf.event', [
            'event' => $event,
            'summary' => app(EventTaskRowSummary::class),
            'logoPath' => $church->logoAbsolutePath(),
        ])->render();

        $this->assertStringNotContainsString('logo-cell', $html);
        $this->assertStringContainsString('<img class="church-logo"', $html);
        $this->assertStringContainsString('max-height: 28px', $html);
        $this->assertStringContainsString($church->logoAbsolutePath(), $html);

        $response = app(EventPdfExporter::class)->stream($event->fresh(['church', 'tasks']));
        $this->assertTrue(
            str_contains($response->headers->get('content-type'), 'pdf')
            || str_contains((string) $response->headers->get('content-disposition'), '.pdf')
        );
    }
}
