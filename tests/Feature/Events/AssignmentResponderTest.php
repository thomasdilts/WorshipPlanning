<?php

namespace Tests\Feature\Events;

use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentResponseMethod;
use App\Enums\AssignmentResponseStatus;
use App\Enums\AssignmentState;
use App\Enums\FieldUsage;
use App\Filament\Pages\MyTasks;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AssignmentResponseReceivedNotification;
use App\Services\AssignmentResponder;
use App\Services\AssignmentStateResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AssignmentResponderTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
    }

    public function test_invitee_can_confirm_and_decline_without_clearing_the_assignment(): void
    {
        $person = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($person, 'Member');
        $task = $this->staffedTask($person);

        EventTaskResponse::factory()->create([
            'church_id' => $this->church->id,
            'event_task_id' => $task->id,
            'assignee_user_id' => $person->id,
            'status' => AssignmentResponseStatus::Invited,
            'occurred_at' => now()->subHour(),
        ]);

        $responder = app(AssignmentResponder::class);
        $responder->confirm($task, $person);

        $this->assertSame(AssignmentState::Confirmed, app(AssignmentStateResolver::class)->for($task->fresh()));
        $this->assertSame($person->id, $task->fresh()->assigned_user_id);

        $responder->decline($task->fresh(), $person, 'Traveling');

        $fresh = $task->fresh(['responses']);
        $this->assertSame(AssignmentState::Declined, app(AssignmentStateResolver::class)->for($fresh));
        $this->assertSame($person->id, $fresh->assigned_user_id);
        $this->assertSame('Traveling', $fresh->responses->last()->note);
    }

    public function test_another_church_member_cannot_respond(): void
    {
        $person = User::factory()->create(['church_id' => $this->church->id]);
        $outsider = User::factory()->create(['church_id' => Church::factory()->create()->id]);
        $task = $this->staffedTask($person);

        $this->expectException(AuthorizationException::class);
        app(AssignmentResponder::class)->confirm($task, $outsider);
    }

    public function test_team_leader_can_respond_for_the_team(): void
    {
        $leader = User::factory()->create(['church_id' => $this->church->id]);
        $member = User::factory()->create(['church_id' => $this->church->id]);
        $team = Team::factory()->create(['church_id' => $this->church->id]);
        $leader->teams()->attach($team->id, ['is_leader' => true]);
        $member->teams()->attach($team->id, ['is_leader' => false]);

        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addDays(3),
        ]);
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::NotUsed,
            'team_usage' => FieldUsage::Required,
        ]);
        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_team_id' => $team->id,
        ]);

        app(AssignmentResponder::class)->confirm($task, $leader);
        $this->assertSame(AssignmentState::Confirmed, app(AssignmentStateResolver::class)->for($task->fresh()));

        $this->expectException(AuthorizationException::class);
        app(AssignmentResponder::class)->decline($task->fresh(), $member);
    }

    public function test_coordinator_can_record_verbal_acceptance(): void
    {
        $admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        $person = User::factory()->create(['church_id' => $this->church->id]);
        $task = $this->staffedTask($person);

        $response = app(AssignmentResponder::class)->confirmVerbally($task, $admin);

        $this->assertSame(AssignmentResponseMethod::Verbal, $response->method);
        $this->assertSame($admin->id, $response->recorded_by_user_id);
        $this->assertNull($response->responder_user_id);
        $this->assertSame(AssignmentState::Confirmed, app(AssignmentStateResolver::class)->for($task->fresh()));
    }

    public function test_my_schedule_accept_action_confirms_an_invitation(): void
    {
        $person = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($person, 'Member');
        $task = $this->staffedTask($person);

        EventTaskResponse::factory()->create([
            'church_id' => $this->church->id,
            'event_task_id' => $task->id,
            'assignee_user_id' => $person->id,
            'status' => AssignmentResponseStatus::Invited,
            'occurred_at' => now(),
        ]);

        $this->actingAs($person);

        Livewire::test(MyTasks::class)
            ->assertSuccessful()
            ->assertSee(__('I can help'))
            ->assertSee(__("I can't make it"))
            ->callTableAction('iCanHelp', $task)
            ->assertHasNoTableActionErrors();

        $this->assertSame(AssignmentState::Confirmed, app(AssignmentStateResolver::class)->for($task->fresh()));
    }

    public function test_my_schedule_decline_action_declines_without_asking_for_a_reason(): void
    {
        $person = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($person, 'Member');
        $task = $this->staffedTask($person);

        EventTaskResponse::factory()->create([
            'church_id' => $this->church->id,
            'event_task_id' => $task->id,
            'assignee_user_id' => $person->id,
            'status' => AssignmentResponseStatus::Invited,
            'occurred_at' => now(),
        ]);

        $this->actingAs($person);

        Livewire::test(MyTasks::class)
            ->mountTableAction('iCantMakeIt', $task)
            ->assertHasNoTableActionErrors();

        $this->assertSame(AssignmentState::Declined, app(AssignmentStateResolver::class)->for($task->fresh()));
    }

    public function test_duplicate_confirm_is_idempotent_and_does_not_renotify(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');
        $person = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($person, 'Member');
        $task = $this->staffedTask($person);

        EventTaskResponse::factory()->create([
            'church_id' => $this->church->id,
            'event_task_id' => $task->id,
            'assignee_user_id' => $person->id,
            'status' => AssignmentResponseStatus::Invited,
            'occurred_at' => now()->subHour(),
        ]);

        $responder = app(AssignmentResponder::class);
        $first = $responder->confirm($task, $person);
        $second = $responder->confirm($task->fresh(['responses']), $person);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $task->responses()->count());
        Notification::assertSentToTimes(
            $admin,
            AssignmentResponseReceivedNotification::class,
            1,
        );
    }

    protected function staffedTask(User $person): EventTask
    {
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addDays(4),
        ]);
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);

        return EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $person->id,
        ]);
    }
}
