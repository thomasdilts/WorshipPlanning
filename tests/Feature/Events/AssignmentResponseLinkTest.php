<?php

namespace Tests\Feature\Events;

use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentResponseStatus;
use App\Enums\AssignmentState;
use App\Enums\FieldUsage;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Notifications\AssignmentResponseReceivedNotification;
use App\Services\AssignmentResponseLink;
use App\Services\AssignmentStateResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AssignmentResponseLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_preview_does_not_mutate_state(): void
    {
        [$admin, $person, $task] = $this->inviteFixture();

        EventTaskResponse::factory()->create([
            'church_id' => $person->church_id,
            'event_task_id' => $task->id,
            'assignee_user_id' => $person->id,
            'status' => AssignmentResponseStatus::Invited,
            'occurred_at' => now(),
        ]);

        $url = app(AssignmentResponseLink::class)->showUrl($task, $person);

        $this->get($url)
            ->assertOk()
            ->assertSee(__('I can help'))
            ->assertSee(__("I can't make it"));

        $this->assertSame(AssignmentState::Invited, app(AssignmentStateResolver::class)->for($task->fresh()));
        $this->assertSame(1, $task->responses()->count());
    }

    public function test_unsigned_get_is_forbidden(): void
    {
        [, $person, $task] = $this->inviteFixture();

        $this->get(route('assignments.respond.show', [
            'task' => $task->id,
            'user' => $person->id,
        ]))->assertForbidden();
    }

    public function test_post_confirm_records_response_and_notifies_coordinator(): void
    {
        Notification::fake();

        [$admin, $person, $task] = $this->inviteFixture();

        EventTaskResponse::factory()->create([
            'church_id' => $person->church_id,
            'event_task_id' => $task->id,
            'assignee_user_id' => $person->id,
            'status' => AssignmentResponseStatus::Invited,
            'occurred_at' => now(),
        ]);

        $url = app(AssignmentResponseLink::class)->storeUrl($task, $person);

        $this->post($url, ['decision' => 'confirm'])
            ->assertRedirect();

        $this->assertSame(AssignmentState::Confirmed, app(AssignmentStateResolver::class)->for($task->fresh()));

        Notification::assertSentTo($admin, AssignmentResponseReceivedNotification::class);
        Notification::assertNotSentTo($person, AssignmentResponseReceivedNotification::class);
    }

    public function test_reassigned_link_is_rejected(): void
    {
        [, $person, $task] = $this->inviteFixture();
        $other = User::factory()->create(['church_id' => $person->church_id]);
        $task->update(['assigned_user_id' => $other->id]);

        $url = app(AssignmentResponseLink::class)->showUrl($task, $person);

        $this->get($url)->assertStatus(410);
    }

    /**
     * @return array{0: User, 1: User, 2: EventTask}
     */
    public function test_opening_accept_and_decline_links_changes_nothing_until_the_button_is_pressed(): void
    {
        [, $person, $task] = $this->inviteFixture();

        $links = app(AssignmentResponseLink::class);
        $storeUrl = route('assignments.quick.store', ['token' => $links->tokenFor($task, $person)->token]);

        $this->get($links->acceptUrl($task, $person))
            ->assertOk()
            ->assertSee('action="'.$storeUrl.'"', false)
            ->assertSee('name="decision" value="confirm"', false)
            ->assertSee(__('Yes, I am coming'))
            ->assertSee(__('Your answer has not been saved yet.'))
            ->assertDontSee('class="status"', false)
            ->assertDontSee('<script', false);

        $this->get($links->declineUrl($task, $person))
            ->assertOk()
            ->assertSee('name="decision" value="decline"', false)
            ->assertSee(__('No, I am not coming'))
            ->assertDontSee('<script', false);

        $state = app(AssignmentStateResolver::class)->for($task->fresh());

        $this->assertNotSame(AssignmentState::Confirmed, $state);
        $this->assertNotSame(AssignmentState::Declined, $state);
    }

    public function test_short_link_confirm_and_decline_record_the_answer_without_login(): void
    {
        [, $person, $task] = $this->inviteFixture();

        $token = app(AssignmentResponseLink::class)->tokenFor($task, $person)->token;

        $this->followingRedirects()
            ->post(route('assignments.quick.store', ['token' => $token]), ['decision' => 'confirm'])
            ->assertOk()
            ->assertSee(__('Thanks — you\'re confirmed'))
            ->assertDontSee(__('Yes, I am coming'));

        $this->assertSame(AssignmentState::Confirmed, app(AssignmentStateResolver::class)->for($task->fresh()));

        $this->post(route('assignments.quick.store', ['token' => $token]), ['decision' => 'decline'])
            ->assertRedirect();

        $this->assertSame(AssignmentState::Declined, app(AssignmentStateResolver::class)->for($task->fresh()));
        $this->assertGuest();
    }

    public function test_unknown_short_link_is_not_found(): void
    {
        $this->get(route('assignments.quick.answer', ['token' => 'doesnotexist', 'answer' => 'yes']))
            ->assertNotFound();
    }

    public function test_short_link_expires_the_day_after_the_event(): void
    {
        [, $person, $task] = $this->inviteFixture();

        $token = app(AssignmentResponseLink::class)->tokenFor($task, $person)->token;

        $this->travelTo($task->event->endsAt()->addDay()->addMinute());

        $this->post(route('assignments.quick.store', ['token' => $token]), ['decision' => 'confirm'])
            ->assertStatus(410);

        $this->assertNotSame(AssignmentState::Confirmed, app(AssignmentStateResolver::class)->for($task->fresh()));
    }

    public function test_short_link_is_rejected_after_reassignment(): void
    {
        [, $person, $task] = $this->inviteFixture();

        $token = app(AssignmentResponseLink::class)->tokenFor($task, $person)->token;
        $task->update(['assigned_user_id' => User::factory()->create(['church_id' => $person->church_id])->id]);

        $this->post(route('assignments.quick.store', ['token' => $token]), ['decision' => 'confirm'])
            ->assertStatus(410);
    }

    public function test_short_link_token_is_reused_for_the_same_task_and_person(): void
    {
        [, $person, $task] = $this->inviteFixture();

        $links = app(AssignmentResponseLink::class);

        $this->assertSame($links->tokenFor($task, $person)->token, $links->tokenFor($task, $person)->token);
    }

    protected function inviteFixture(): array
    {
        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $person = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($person, 'Member');

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'starts_at' => now()->addDays(5),
        ]);
        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $person->id,
        ]);

        return [$admin, $person, $task];
    }
}
