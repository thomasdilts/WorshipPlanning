<?php

namespace Tests\Feature\Support;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Pages\ChurchSettings;
use App\Models\Church;
use App\Models\Unavailability;
use App\Models\User;
use App\Support\DateTimeFormats;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DateTimeFormatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_churches_show_24_hour_time(): void
    {
        $church = Church::factory()->create();

        $this->assertTrue($church->refresh()->uses_24_hour_time);
        $this->assertSame('14:30', DateTimeFormats::formatTime('14:30:00', $church));
    }

    public function test_church_that_turns_off_24_hour_time_shows_am_pm(): void
    {
        $church = Church::factory()->create(['uses_24_hour_time' => false]);

        $this->assertSame('2:30 PM', DateTimeFormats::formatTime(Carbon::parse('2026-11-11 14:30'), $church));
    }

    public function test_current_church_setting_applies_when_no_church_is_given(): void
    {
        app(CurrentChurch::class)->set(Church::factory()->create(['uses_24_hour_time' => false]));

        $this->assertSame('9:05 AM', DateTimeFormats::formatTime('09:05:00'));
    }

    public function test_written_dates_follow_the_swedish_language(): void
    {
        App::setLocale('sv');

        $unavailability = Unavailability::factory()->create([
            'starts_on' => '2026-11-11',
            'ends_on' => '2026-11-12',
        ]);

        $this->assertSame('11 nov 2026 – 12 nov 2026', $unavailability->label());
    }

    public function test_admin_can_switch_the_church_to_12_hour_time(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        app(CurrentChurch::class)->set($church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $this->actingAs($admin);

        Livewire::test(ChurchSettings::class)
            ->assertFormSet(['uses_24_hour_time' => true])
            ->fillForm(['uses_24_hour_time' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($church->refresh()->uses_24_hour_time);
    }
}
