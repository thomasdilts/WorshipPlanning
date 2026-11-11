<?php

namespace Tests\Feature\Events;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Church;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventListDateFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_services_show_twenty_five_rows_per_page_by_default(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);
        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        Event::factory()->count(26)->create([
            'church_id' => $church->id,
            'starts_at' => now()->addDay()->setTime(10, 0),
        ]);
        $this->actingAs($admin);

        $component = Livewire::test(ListEvents::class)->assertSuccessful();

        $this->assertCount(25, $component->instance()->getTableRecords());

        $component->call('setPage', 2)->assertSuccessful();

        $this->assertCount(1, $component->instance()->getTableRecords());
    }

    public function test_events_list_defaults_to_today_and_forward(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $past = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Past service',
            'starts_at' => now()->subDays(3)->setTime(10, 0),
        ]);

        $today = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Today service',
            'starts_at' => now()->setTime(10, 0),
        ]);

        $future = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Future service',
            'starts_at' => now()->addDays(5)->setTime(10, 0),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListEvents::class)
            ->assertCanSeeTableRecords([$today, $future])
            ->assertCanNotSeeTableRecords([$past]);
    }

    public function test_clearing_from_date_shows_past_events(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $past = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Past service',
            'starts_at' => now()->subDays(3)->setTime(10, 0),
        ]);

        $this->actingAs($admin);

        $component = Livewire::test(ListEvents::class)
            ->assertCanNotSeeTableRecords([$past]);

        $this->assertSame([], $component->instance()->eventNameOptions());

        $component->set('tableFilters.from_date.date', null)
            ->assertCanSeeTableRecords([$past]);

        $this->assertSame(['Past service' => 'Past service'], $component->instance()->eventNameOptions());
    }

    public function test_event_types_apply_immediately_and_combine_with_search_and_date_bounds(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00'));
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);
        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        $morning = Event::factory()->create([
            'church_id' => $church->id, 'name' => 'Morning service',
            'starts_at' => '2026-10-25 10:00',
        ]);
        $prayer = Event::factory()->create([
            'church_id' => $church->id, 'name' => 'Prayer meeting', 'description' => 'Quiet reflection',
            'starts_at' => '2026-11-02 19:00',
        ]);
        $evening = Event::factory()->create([
            'church_id' => $church->id, 'name' => 'Evening service',
            'starts_at' => '2026-11-03 18:00',
        ]);
        $this->actingAs($admin);

        $component = Livewire::test(ListEvents::class)
            ->assertSuccessful()
            ->assertSet('tableFilters.from_date.date', '2026-10-22')
            ->call('selectEventType', 'Morning service')
            ->assertCanSeeTableRecords([$morning])
            ->assertCanNotSeeTableRecords([$prayer, $evening]);

        $component->set('tableFilters.events.names', ['Morning service', 'Prayer meeting'])
            ->assertCanSeeTableRecords([$morning, $prayer])
            ->assertCanNotSeeTableRecords([$evening])
            ->call('selectEventTypes', false)
            ->assertCanNotSeeTableRecords([$morning, $prayer, $evening])
            ->call('selectEventType')
            ->assertCanSeeTableRecords([$morning, $prayer, $evening]);

        $component->set('tableFilters.from_date.until', '2026-11-02')
            ->set('tableFilters.from_date.date', '2026-11-02')
            ->assertCanSeeTableRecords([$prayer])
            ->assertCanNotSeeTableRecords([$morning, $evening])
            ->set('tableSearch', 'quiet reflection')
            ->assertCanSeeTableRecords([$prayer])
            ->set('tableSearch', 'no matching service')
            ->assertCanNotSeeTableRecords([$prayer]);

        $component->set('tableSearch', '')
            ->call('showEventsFromToday')
            ->assertSet('tableFilters.from_date.date', '2026-10-22')
            ->assertSet('tableFilters.from_date.until', null)
            ->assertCanSeeTableRecords([$morning, $prayer, $evening]);
    }

    public function test_event_type_options_and_results_are_scoped_to_the_current_church(): void
    {
        $church = Church::factory()->create();
        $otherChurch = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        $mine = Event::factory()->create([
            'church_id' => $church->id, 'name' => 'Our service',
            'starts_at' => now()->addDay(),
        ]);
        $theirs = Event::factory()->create([
            'church_id' => $otherChurch->id, 'name' => 'Private service',
            'starts_at' => now()->addDay(),
        ]);
        app(CurrentChurch::class)->set($church);
        $this->actingAs($admin);

        $component = Livewire::test(ListEvents::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        $this->assertSame(['Our service' => 'Our service'], $component->instance()->eventNameOptions());
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function toolbarLocales(): array
    {
        return [
            'English' => ['en', 'Search services', 'Create service', 'Today'],
            'Swedish' => ['sv', 'Sök evenemang', 'Skapa evenemang', 'Idag'],
            'Arabic' => ['ar', 'ابحث عن خدمات', 'إنشاء حدث', 'اليوم'],
        ];
    }

    #[DataProvider('toolbarLocales')]
    public function test_services_toolbar_uses_the_interface_language(string $locale, string $search, string $create, string $today): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);
        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        $this->actingAs($admin);
        app()->setLocale($locale);

        Livewire::test(ListEvents::class)
            ->assertSuccessful()
            ->assertSee($search)
            ->assertSee($create)
            ->assertSee($today);
    }
}
