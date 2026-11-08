<?php

namespace Tests\Feature\Events;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\AssignmentResponseStatus;
use App\Enums\AssignmentState;
use App\Enums\FieldUsage;
use App\Filament\Resources\Events\Schemas\EventTaskForm;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\TaskTemplate;
use App\Models\Unavailability;
use App\Models\User;
use App\Services\AssignmentRequiredWindow;
use App\Services\AssignmentStateResolver;
use App\Services\EventTaskTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Unavailability\Services\UnavailabilityConflictService;
use Tests\TestCase;

class MaterialAssignmentChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_task_requests_reconfirm_when_duration_shifts_window(): void
    {
        [$event, $first, $second, $person] = $this->twoTimedTasks();

        EventTaskResponse::factory()->create([
            'church_id' => $person->church_id,
            'event_task_id' => $second->id,
            'assignee_user_id' => $person->id,
            'status' => AssignmentResponseStatus::Confirmed,
            'occurred_at' => now()->subHour(),
        ]);

        $this->assertSame(AssignmentState::Confirmed, app(AssignmentStateResolver::class)->for($second->fresh(['responses'])));

        EventTaskForm::persist($first->fresh(), [
            'task_template_id' => $first->task_template_id,
            'name' => $first->name,
            'duration' => 25,
            'assigned_user_id' => null,
            'assigned_team_id' => null,
            'description' => null,
            'song' => null,
            'song_id' => null,
            'special_needs' => null,
            'bible_passages' => [],
            'picture_ids' => [],
            'shared_file_ids' => [],
            'sort_order' => $first->sort_order,
        ], $event->fresh());

        $second = $second->fresh(['responses']);
        $this->assertSame(AssignmentState::Invited, app(AssignmentStateResolver::class)->for($second));
        $this->assertSame(
            AssignmentResponseStatus::ReconfirmRequested,
            $second->responses->last()->status
        );
        $this->assertSame((int) $person->id, (int) $second->assigned_user_id);
    }

    public function test_non_material_edit_keeps_confirmation(): void
    {
        [$event, $first, , $person] = $this->twoTimedTasks();

        EventTaskResponse::factory()->create([
            'church_id' => $person->church_id,
            'event_task_id' => $first->id,
            'assignee_user_id' => $person->id,
            'status' => AssignmentResponseStatus::Confirmed,
            'occurred_at' => now()->subHour(),
        ]);

        EventTaskForm::persist($first->fresh(), [
            'task_template_id' => $first->task_template_id,
            'name' => $first->name,
            'duration' => $first->duration,
            'assigned_user_id' => $person->id,
            'assigned_team_id' => null,
            'description' => 'Updated notes only',
            'song' => null,
            'song_id' => null,
            'special_needs' => null,
            'bible_passages' => [],
            'picture_ids' => [],
            'shared_file_ids' => [],
            'sort_order' => $first->sort_order,
        ], $event->fresh());

        $this->assertSame(
            AssignmentState::Confirmed,
            app(AssignmentStateResolver::class)->for($first->fresh(['responses']))
        );
    }

    public function test_unavailability_uses_task_window_on_multi_day_event(): void
    {
        $church = Church::factory()->create();
        app(ChurchModuleManager::class)->enable($church, 'unavailability');

        $person = User::factory()->create(['church_id' => $church->id, 'display_name' => 'Jamie']);
        $event = Event::factory()->create([
            'church_id' => $church->id,
            'starts_at' => '2026-11-06 18:00:00', // Friday evening
        ]);
        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'use_globally' => false,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);

        // Long first block Friday night; Saturday morning slot is second.
        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'name' => 'Friday block',
            'duration' => 60 * 14, // through to Sat 08:00
            'sort_order' => 1,
        ]);
        $saturday = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'name' => 'Saturday slot',
            'assigned_user_id' => $person->id,
            'duration' => 30,
            'sort_order' => 2,
        ]);

        app(EventTaskTimeline::class)->recalculate($event);

        Unavailability::factory()->create([
            'church_id' => $church->id,
            'user_id' => $person->id,
            'starts_on' => '2026-11-06',
            'ends_on' => '2026-11-06',
        ]);

        $messages = app(UnavailabilityConflictService::class)
            ->warningMessagesForEvent($event->fresh(['tasks.assignedUser', 'tasks.assignedTeam', 'tasks.template', 'church']));

        $this->assertSame([], $messages, 'Friday-only unavailability must not flag Saturday required window');

        // Control: event-wide check would still see Friday in [Fri,Sun] — prove task window is used
        $window = app(AssignmentRequiredWindow::class)->forTask($saturday->fresh(), $event->fresh());
        $this->assertSame('2026-11-07', $window['from']->toDateString());
    }

    /**
     * @return array{0: Event, 1: EventTask, 2: EventTask, 3: User}
     */
    protected function twoTimedTasks(): array
    {
        $church = Church::factory()->create();
        $person = User::factory()->create(['church_id' => $church->id]);
        $event = Event::factory()->create([
            'church_id' => $church->id,
            'starts_at' => '2026-11-01 10:00:00',
        ]);
        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'use_globally' => false,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
            'default_duration' => 10,
        ]);

        $first = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'name' => 'First',
            'duration' => 10,
            'sort_order' => 1,
        ]);
        $second = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'name' => 'Second',
            'assigned_user_id' => $person->id,
            'duration' => 10,
            'sort_order' => 2,
        ]);

        app(EventTaskTimeline::class)->recalculate($event);

        return [$event->fresh(), $first->fresh(), $second->fresh(), $person];
    }
}
