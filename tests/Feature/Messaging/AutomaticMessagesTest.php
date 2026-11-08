<?php

namespace Tests\Feature\Messaging;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentResponseStatus;
use App\Enums\MessagePreference;
use App\Filament\Resources\AutomaticMessages\AutomaticMessageResource;
use App\Filament\Resources\AutomaticMessages\Pages\CreateAutomaticMessage;
use App\Models\AutomaticMessage;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventMessage;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\MessageTemplate;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AutomaticMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $admin;

    protected User $person;

    protected User $leader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 12:00:00');
        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($this->church, 'messaging', [
            'email' => ['host' => 'smtp.test', 'port' => 587, 'from_address' => 'church@test', 'from_name' => 'Church'],
        ]);
        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);

        $this->admin = $this->userWithRole('Church Admin');
        $this->person = User::factory()->create(['church_id' => $this->church->id, 'message_preference' => MessagePreference::Email]);
        $this->leader = User::factory()->create(['church_id' => $this->church->id, 'message_preference' => MessagePreference::Email]);
    }

    public function test_due_message_is_sent_once_to_every_person_and_team_leader_on_the_service(): void
    {
        $automaticMessage = AutomaticMessage::factory()->create(['church_id' => $this->church->id, 'hours_before' => 72, 'created_by_user_id' => $this->admin->id]);
        $this->travel(2)->days();
        $service = $this->serviceStartingIn(hours: 71);

        $this->artisan('worship:send-automatic-messages')->assertSuccessful();
        $this->artisan('worship:send-automatic-messages')->assertSuccessful();

        $messages = EventMessage::query()->where('event_id', $service->id)->with('recipients')->get();

        $this->assertCount(2, $messages);
        $this->assertEqualsCanonicalizing([$this->person->id, $this->leader->id], $messages->flatMap->recipients->pluck('id')->all());
        $this->assertSame([EventMessage::STATUS_SENT], $messages->pluck('status')->unique()->values()->all());
        $this->assertSame([$this->admin->id], $messages->pluck('created_by_user_id')->unique()->values()->all());
        $this->assertSame($automaticMessage->template->id, $messages->first()->message_template_id);
        $this->assertSame(1, $automaticMessage->sentForEvents()->count());
    }

    public function test_nothing_is_sent_before_the_window_for_inactive_messages_or_for_services_already_inside_it_when_created(): void
    {
        $later = $this->serviceStartingIn(hours: 100);
        AutomaticMessage::factory()->create(['church_id' => $this->church->id, 'hours_before' => 72]);
        AutomaticMessage::factory()->inactive()->create(['church_id' => $this->church->id, 'hours_before' => 120]);
        $this->serviceStartingIn(hours: 24);

        $this->artisan('worship:send-automatic-messages')->assertSuccessful();

        $this->assertSame(0, EventMessage::query()->count());

        $this->travel(30)->hours();
        $this->artisan('worship:send-automatic-messages')->assertSuccessful();

        $this->assertSame([$later->id], EventMessage::query()->pluck('event_id')->unique()->values()->all());
    }

    public function test_people_who_declined_are_left_out(): void
    {
        AutomaticMessage::factory()->create(['church_id' => $this->church->id, 'hours_before' => 24]);
        $this->travel(1)->days();
        $service = $this->serviceStartingIn(hours: 23);
        $declined = $service->tasks()->where('assigned_user_id', $this->person->id)->sole();
        EventTaskResponse::factory()->create([
            'church_id' => $this->church->id,
            'event_task_id' => $declined->id,
            'assignee_user_id' => $this->person->id,
            'status' => AssignmentResponseStatus::Declined,
        ]);

        $this->artisan('worship:send-automatic-messages')->assertSuccessful();

        $this->assertSame([$this->leader->id], EventMessage::query()->with('recipients')->get()->flatMap->recipients->pluck('id')->all());
    }

    public function test_church_admin_defines_an_automatic_message(): void
    {
        $template = MessageTemplate::query()->create(['church_id' => $this->church->id, 'name' => 'Reminder', 'purpose' => 'service', 'body' => '<p>Hi</p>', 'is_active' => true]);
        $this->actingAs($this->admin);

        Livewire::test(CreateAutomaticMessage::class)
            ->fillForm(['name' => 'Three days before', 'hours_before' => 72, 'message_template_id' => $template->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $automaticMessage = AutomaticMessage::query()->sole();

        $this->assertSame(72, $automaticMessage->hours_before);
        $this->assertSame($this->admin->id, $automaticMessage->created_by_user_id);
        $this->assertTrue($automaticMessage->is_active);
    }

    public function test_only_church_admins_can_open_automatic_messages(): void
    {
        $this->actingAs($this->userWithRole('Event Planner'));
        $this->get(AutomaticMessageResource::getUrl())->assertForbidden();

        $this->actingAs($this->admin);
        $this->get(AutomaticMessageResource::getUrl())->assertOk();
    }

    protected function serviceStartingIn(int $hours): Event
    {
        $event = Event::factory()->create(['church_id' => $this->church->id, 'starts_at' => now()->addHours($hours)]);
        $taskTemplate = TaskTemplate::factory()->create(['church_id' => $this->church->id, 'use_globally' => false]);
        $team = Team::factory()->create(['church_id' => $this->church->id]);
        $this->leader->teams()->attach($team->id, ['is_leader' => true]);

        EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $taskTemplate->id, 'assigned_user_id' => $this->person->id]);
        EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $taskTemplate->id, 'assigned_team_id' => $team->id]);
        EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $taskTemplate->id]);

        return $event;
    }

    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, $role);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);

        return $user;
    }
}
