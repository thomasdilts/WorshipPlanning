<?php

namespace Tests\Feature\Events;

use App\Enums\AssignmentResponseMethod;
use App\Enums\AssignmentResponseStatus;
use App\Enums\AssignmentState;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\Team;
use App\Models\User;
use App\Services\AssignmentStateResolver;
use App\Services\EventCopier;
use App\Services\LegacyNotificationMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class AssignmentResponseTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->event = Event::factory()->create(['church_id' => $this->church->id]);
    }

    private function member(): User
    {
        return User::factory()->create(['church_id' => $this->church->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(array $attributes = []): EventTask
    {
        return EventTask::factory()->create($attributes + ['event_id' => $this->event->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function response(EventTask $task, AssignmentResponseStatus $status, string $at, array $attributes = []): EventTaskResponse
    {
        return EventTaskResponse::factory()->create($attributes + [
            'church_id' => $this->church->id,
            'event_task_id' => $task->id,
            'assignee_user_id' => $task->assigned_user_id,
            'assignee_team_id' => $task->assigned_team_id,
            'status' => $status,
            'occurred_at' => $at,
        ]);
    }

    private function stateOf(EventTask $task): AssignmentState
    {
        return app(AssignmentStateResolver::class)->for($task->fresh());
    }

    public function test_a_position_without_assignee_is_vacant_not_a_fake_person(): void
    {
        $this->assertSame(AssignmentState::Vacant, $this->stateOf($this->task()));
    }

    public function test_an_assigned_person_with_no_history_is_a_draft(): void
    {
        $task = $this->task(['assigned_user_id' => $this->member()->id]);

        $this->assertSame(AssignmentState::Draft, $this->stateOf($task));
    }

    public function test_latest_response_wins_and_a_reminder_does_not_undo_an_acceptance(): void
    {
        $task = $this->task(['assigned_user_id' => $this->member()->id]);

        $this->response($task, AssignmentResponseStatus::Invited, '2026-10-01 10:00');
        $this->assertSame(AssignmentState::Invited, $this->stateOf($task));

        $this->response($task, AssignmentResponseStatus::Confirmed, '2026-10-02 10:00');
        $this->response($task, AssignmentResponseStatus::Notified, '2026-10-05 10:00');
        $this->assertSame(AssignmentState::Confirmed, $this->stateOf($task));

        $this->response($task, AssignmentResponseStatus::Declined, '2026-10-06 10:00');
        $this->assertSame(AssignmentState::Declined, $this->stateOf($task));
    }

    public function test_a_person_who_was_only_notified_is_informed(): void
    {
        $task = $this->task(['assigned_user_id' => $this->member()->id]);
        $this->response($task, AssignmentResponseStatus::Notified, '2026-10-01 10:00');

        $this->assertSame(AssignmentState::Informed, $this->stateOf($task));
    }

    public function test_the_previous_assignees_answer_does_not_carry_over_to_a_replacement(): void
    {
        $first = $this->member();
        $task = $this->task(['assigned_user_id' => $first->id]);
        $this->response($task, AssignmentResponseStatus::Confirmed, '2026-10-01 10:00');

        $task->update(['assigned_user_id' => $this->member()->id]);

        $this->assertSame(AssignmentState::Draft, $this->stateOf($task));
    }

    public function test_a_team_is_assumed_accepted_until_its_leader_says_otherwise(): void
    {
        $team = Team::factory()->create(['church_id' => $this->church->id]);
        $leader = $this->member();
        $task = $this->task(['assigned_team_id' => $team->id]);

        $this->assertSame(AssignmentState::AssumedTeam, $this->stateOf($task));

        $this->response($task, AssignmentResponseStatus::Notified, '2026-10-01 10:00');
        $this->assertSame(AssignmentState::AssumedTeam, $this->stateOf($task));

        $this->response($task, AssignmentResponseStatus::Declined, '2026-10-02 10:00', ['responder_user_id' => $leader->id]);
        $this->assertSame(AssignmentState::Declined, $this->stateOf($task));
    }

    public function test_resolving_many_tasks_returns_a_state_per_task(): void
    {
        $vacant = $this->task();
        $drafted = $this->task(['assigned_user_id' => $this->member()->id]);

        $states = app(AssignmentStateResolver::class)->forMany(EventTask::query()->whereIn('id', [$vacant->id, $drafted->id])->get());

        $this->assertSame(AssignmentState::Vacant, $states[$vacant->id]);
        $this->assertSame(AssignmentState::Draft, $states[$drafted->id]);
    }

    public function test_a_response_must_be_about_exactly_one_assignee(): void
    {
        $task = $this->task();

        $this->expectException(InvalidArgumentException::class);
        EventTaskResponse::query()->create([
            'church_id' => $this->church->id,
            'event_task_id' => $task->id,
            'status' => AssignmentResponseStatus::Invited,
        ]);
    }

    public function test_a_response_cannot_be_filed_under_another_church(): void
    {
        $task = $this->task(['assigned_user_id' => $this->member()->id]);
        $other = Church::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        EventTaskResponse::query()->create([
            'church_id' => $other->id,
            'event_task_id' => $task->id,
            'assignee_user_id' => $task->assigned_user_id,
            'status' => AssignmentResponseStatus::Invited,
        ]);
    }

    public function test_copying_an_event_starts_with_clean_response_history(): void
    {
        $task = $this->task(['assigned_user_id' => $this->member()->id]);
        $this->response($task, AssignmentResponseStatus::Confirmed, '2026-10-01 10:00');

        $copies = app(EventCopier::class)->copy($this->event->fresh(), [
            'starts_at' => now()->addWeek(),
            'copy_people_and_teams' => true,
        ]);

        $copiedTask = $copies->first()->tasks->first();
        $this->assertSame($task->assigned_user_id, $copiedTask->assigned_user_id);
        $this->assertSame(0, $copiedTask->responses()->count());
        $this->assertSame(AssignmentState::Draft, $this->stateOf($copiedTask));
        $this->assertSame(1, EventTaskResponse::query()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function legacy(int $id, string $status, array $attributes = []): array
    {
        return $attributes + [
            'id' => $id,
            'status' => $status,
            'activity_id' => 10,
            'user_id' => 20,
            'team_id' => null,
            'user_from_id' => 30,
            'message_name' => 'Mejl - Kräva svar',
            'notified_date' => '2022-10-30 13:21:17',
            'notify_replied_date' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $legacyRows
     * @return array{rows: list<array<string, mixed>>, skipped: array<string, int>, unknown_method: int}
     */
    private function mapLegacy(array $legacyRows, ?EventTask $task = null): array
    {
        return app(LegacyNotificationMapper::class)->map($this->church->id, $legacyRows, [
            'tasks' => [10 => ($task ?? $this->task())->id],
            'users' => [20 => $this->member()->id, 30 => $this->member()->id],
            'teams' => [5 => Team::factory()->create(['church_id' => $this->church->id])->id],
        ]);
    }

    public function test_legacy_statuses_map_without_being_conflated(): void
    {
        $result = $this->mapLegacy([
            $this->legacy(1, 'Not replied yet'),
            $this->legacy(2, 'Accepted', ['notify_replied_date' => '2022-10-30 19:07:53']),
            $this->legacy(3, 'Accepted', ['message_name' => 'Accepterade uppgiften muntligt']),
            $this->legacy(4, 'Rejected', ['notify_replied_date' => '2022-10-31 08:00:00']),
            $this->legacy(5, 'No reply requested', ['message_name' => 'Mejl - Påminnelse endast']),
            $this->legacy(6, 'Accepted'),
            $this->legacy(7, 'Something new'),
        ]);

        $byLegacyId = collect($result['rows'])->keyBy('legacy_id');

        $this->assertSame('invited', $byLegacyId[1]['status']);
        $this->assertNotNull($byLegacyId[1]['recorded_by_user_id']);

        $this->assertSame(['confirmed', 'replied'], [$byLegacyId[2]['status'], $byLegacyId[2]['method']]);
        $this->assertEquals(Carbon::parse('2022-10-30 19:07:53'), $byLegacyId[2]['occurred_at']);
        $this->assertSame($byLegacyId[2]['assignee_user_id'], $byLegacyId[2]['responder_user_id']);

        $this->assertSame(['confirmed', 'verbal'], [$byLegacyId[3]['status'], $byLegacyId[3]['method']]);
        $this->assertNotNull($byLegacyId[3]['recorded_by_user_id']);

        $this->assertSame(['declined', 'replied'], [$byLegacyId[4]['status'], $byLegacyId[4]['method']]);
        $this->assertSame('notified', $byLegacyId[5]['status']);

        $this->assertSame(['confirmed', null], [$byLegacyId[6]['status'], $byLegacyId[6]['method']]);
        $this->assertSame(1, $result['unknown_method']);
        $this->assertSame('unknown', $byLegacyId[7]['status']);
    }

    public function test_legacy_team_rows_belong_to_the_team_and_name_the_leader_as_responder(): void
    {
        $result = $this->mapLegacy([
            $this->legacy(1, 'Accepted', ['team_id' => 5, 'notify_replied_date' => '2022-10-30 19:07:53']),
        ]);

        $row = $result['rows'][0];
        $this->assertNull($row['assignee_user_id']);
        $this->assertNotNull($row['assignee_team_id']);
        $this->assertNotNull($row['responder_user_id']);
    }

    public function test_legacy_rows_pointing_at_missing_imports_are_skipped_and_counted(): void
    {
        $result = $this->mapLegacy([
            $this->legacy(1, 'Accepted', ['activity_id' => 999]),
            $this->legacy(2, 'Accepted', ['user_id' => 999]),
            $this->legacy(3, 'Accepted', ['team_id' => 999]),
        ]);

        $this->assertSame([], $result['rows']);
        $this->assertSame(['task_not_imported' => 1, 'user_not_imported' => 1, 'team_not_imported' => 1], $result['skipped']);
    }

    public function test_mapped_legacy_rows_insert_and_resolve_to_the_expected_state(): void
    {
        $task = $this->task();
        $person = $this->member();
        $task->update(['assigned_user_id' => $person->id]);

        $result = app(LegacyNotificationMapper::class)->map($this->church->id, [
            $this->legacy(1, 'Accepted', ['message_name' => 'Accepterade uppgiften muntligt', 'notified_date' => '2022-10-01 09:00:00']),
            $this->legacy(2, 'No reply requested', ['message_name' => 'Mejl - Påminnelse endast', 'notified_date' => '2022-10-05 09:00:00']),
        ], ['tasks' => [10 => $task->id], 'users' => [20 => $person->id, 30 => $this->member()->id], 'teams' => []]);

        foreach ($result['rows'] as $row) {
            EventTaskResponse::query()->create($row);
        }

        $this->assertSame(AssignmentState::Confirmed, $this->stateOf($task));
        $this->assertSame(AssignmentResponseMethod::Verbal, $task->responses()->first()->method);
    }
}
