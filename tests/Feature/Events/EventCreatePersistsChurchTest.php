<?php

namespace Tests\Feature\Events;

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Models\Church;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EventCreatePersistsChurchTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_event_sets_church_id_from_the_user(): void
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $this->actingAs($admin);

        Livewire::test(CreateEvent::class)
            ->fillForm([
                'name' => 'Sunday Service',
                'description' => 'Main gathering',
                'starts_at' => now()->addDays(2)->setTime(10, 0),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $event = Event::query()->withoutGlobalScopes()->first();

        $this->assertNotNull($event);
        $this->assertSame($church->id, $event->church_id);
        $this->assertSame('Sunday Service', $event->name);
    }
}
