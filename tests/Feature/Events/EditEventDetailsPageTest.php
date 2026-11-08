<?php

namespace Tests\Feature\Events;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\EditEventDetails;
use App\Models\Church;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditEventDetailsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_workspace_has_no_details_form_fields(): void
    {
        [$admin, $event] = $this->seedAdminAndEvent();

        $this->actingAs($admin);

        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->assertSuccessful()
            ->assertFormFieldDoesNotExist('name')
            ->assertFormFieldDoesNotExist('starts_at');
    }

    public function test_details_page_can_update_event_fields(): void
    {
        [$admin, $event] = $this->seedAdminAndEvent();

        $this->actingAs($admin);

        Livewire::test(EditEventDetails::class, ['record' => $event->getRouteKey()])
            ->assertSuccessful()
            ->fillForm([
                'name' => 'Updated Service',
                'description' => 'New description',
                'starts_at' => $event->starts_at,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated Service', $event->fresh()->name);
        $this->assertSame('New description', $event->fresh()->description);
    }

    /**
     * @return array{0: User, 1: Event}
     */
    protected function seedAdminAndEvent(): array
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'name' => 'Original Service',
        ]);

        app(CurrentChurch::class)->set($church);

        return [$admin, $event];
    }
}
