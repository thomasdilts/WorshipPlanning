<?php

namespace Tests\Feature\Events;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentResponseStatus;
use App\Enums\FieldUsage;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\TaskSpreadsheet;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\Role;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\Unavailability;
use App\Models\User;
use App\Services\TaskSpreadsheetBuilder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaskSpreadsheetTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($this->church, 'unavailability');
        app(ChurchModuleManager::class)->enable($this->church, 'teams');

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
    }

    public function test_builder_columns_and_unavailability(): void
    {
        $personTemplate = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Worship leader',
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
            'default_order' => 1,
        ]);

        $notesTemplate = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Service notes',
            'person_usage' => FieldUsage::NotUsed,
            'team_usage' => FieldUsage::NotUsed,
            'description_usage' => FieldUsage::Optional,
            'show_in_spreadsheet' => true,
            'default_order' => 2,
            'description' => 'Template default — must not appear',
        ]);

        $assignee = User::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Alice',
            'display_name' => 'Alice',
        ]);
        $unavailable = User::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Bob',
            'display_name' => 'Bob',
        ]);

        Unavailability::factory()->create([
            'church_id' => $this->church->id,
            'user_id' => $unavailable->id,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-02',
        ]);

        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Sunday Service',
            'starts_at' => '2026-11-01 10:00:00',
            'ends_at' => '2026-11-01 12:00:00',
        ]);

        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $personTemplate->id,
            'assigned_user_id' => $assignee->id,
        ]);
        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $notesTemplate->id,
            'description' => 'Bring communion cups',
            'song' => 'Amazing Grace',
        ]);

        // Past event must not contribute columns/rows
        $past = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => '2026-01-01 10:00:00',
            'ends_at' => '2026-01-01 12:00:00',
        ]);
        EventTask::factory()->create([
            'event_id' => $past->id,
            'task_template_id' => $personTemplate->id,
            'assigned_user_id' => $assignee->id,
        ]);

        $matrix = app(TaskSpreadsheetBuilder::class)->build($this->church, '2026-10-20');

        $this->assertCount(1, $matrix['staffing_columns']);
        $this->assertSame('Worship leader', $matrix['staffing_columns'][0]['name']);
        $this->assertCount(1, $matrix['extra_columns']);
        $this->assertSame('Service notes', $matrix['extra_columns'][0]['name']);
        $this->assertSame('spreadsheet_'.$notesTemplate->id, $matrix['extra_columns'][0]['key']);

        $row = $matrix['rows']->first();
        $this->assertSame('Sunday Service', $row['event_name']);
        $this->assertStringContainsString('Alice', strip_tags($row['template_'.$personTemplate->id]));
        $this->assertStringContainsString('wp-user-avatar', $row['template_'.$personTemplate->id]);
        $this->assertSame('Bring communion cups', $row['spreadsheet_'.$notesTemplate->id]);
        $this->assertStringNotContainsString('Template default', $row['spreadsheet_'.$notesTemplate->id]);
        $this->assertSame('Bob', $row['unavailable']);
        $this->assertCount(1, $matrix['rows']);
    }

    public function test_services_uses_the_toolbar_without_duplicate_spreadsheet_navigation(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListEvents::class)
            ->assertSuccessful()
            ->assertSee('Create event')
            ->assertDontSee('Tasks by event');

        Livewire::test(TaskSpreadsheet::class)
            ->assertSuccessful()
            ->assertSee('Export spreadsheet')
            ->assertSee('Week');
    }

    public function test_event_filter_multi_select_limits_visible_rows(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);

        $morning = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Morning service',
            'starts_at' => now()->addDays(2)->setTime(10, 0),
            'ends_at' => now()->addDays(2)->setTime(12, 0),
        ]);
        $evening = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Evening service',
            'starts_at' => now()->addDays(3)->setTime(18, 0),
            'ends_at' => now()->addDays(3)->setTime(20, 0),
        ]);

        EventTask::factory()->create([
            'event_id' => $morning->id,
            'task_template_id' => $template->id,
        ]);
        EventTask::factory()->create([
            'event_id' => $evening->id,
            'task_template_id' => $template->id,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(TaskSpreadsheet::class)
            ->assertSuccessful()
            ->assertSee('Morning service')
            ->assertSee('Evening service')
            ->assertSee('Select all')
            ->assertSee('Select none')
            ->assertSee('Showing 1 to 2 of 2 results')
            ->set('tableFilters', [
                'events' => [
                    'names' => ['Morning service'],
                ],
            ])
            ->assertSee('Showing 1 result')
            ->assertCanSeeTableRecords(['event:'.$morning->id])
            ->assertCanNotSeeTableRecords(['event:'.$evening->id])
            ->set('tableFilters', [
                'events' => [
                    'names' => [],
                ],
            ])
            ->assertSee('No upcoming events')
            ->assertCanNotSeeTableRecords(['event:'.$morning->id, 'event:'.$evening->id]);
    }

    public function test_event_filter_options_are_unique_names(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);

        foreach ([2, 3, 4] as $days) {
            $event = Event::factory()->create([
                'church_id' => $this->church->id,
                'name' => 'Sunday service',
                'starts_at' => now()->addDays($days)->setTime(10, 0),
                'ends_at' => now()->addDays($days)->setTime(12, 0),
            ]);
            EventTask::factory()->create([
                'event_id' => $event->id,
                'task_template_id' => $template->id,
            ]);
        }

        $this->actingAs($this->admin);

        $component = Livewire::test(TaskSpreadsheet::class)
            ->assertSuccessful()
            ->assertSee('Showing 1 to 3 of 3 results');

        $options = (new \ReflectionMethod(TaskSpreadsheet::class, 'eventNameOptions'))
            ->invoke($component->instance());

        $this->assertSame(['Sunday service' => 'Sunday service'], $options);

        $component
            ->set('tableFilters', [
                'events' => [
                    'names' => ['Sunday service'],
                ],
            ])
            ->assertSee('Showing 1 to 3 of 3 results');
    }

    public function test_team_assignment_appears_in_staffing_cell(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Band',
            'person_usage' => FieldUsage::NotUsed,
            'team_usage' => FieldUsage::Required,
        ]);
        $team = Team::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Choir',
        ]);
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
        ]);
        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_team_id' => $team->id,
        ]);

        $matrix = app(TaskSpreadsheetBuilder::class)->build($this->church);

        $row = $matrix['rows']->first();
        $this->assertSame('Choir', $row['template_'.$template->id]);
        $this->assertSame($task->id, $row['template_'.$template->id.'_task_id']);
        $this->assertFalse($row['template_'.$template->id.'_vacant']);
        $this->assertSame('assumed_team', $row['template_'.$template->id.'_state']);
    }

    public function test_unassigned_staffing_cell_is_vacant_not_an_error(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Reader',
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ]);
        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => null,
            'assigned_team_id' => null,
        ]);

        $matrix = app(TaskSpreadsheetBuilder::class)->build($this->church);
        $row = $matrix['rows']->first();

        $this->assertTrue($row['template_'.$template->id.'_vacant']);
        $this->assertSame('vacant', $row['template_'.$template->id.'_state']);
        $this->assertSame($task->id, $row['template_'.$template->id.'_task_id']);
    }

    public function test_spreadsheet_opens_shared_edit_task_modal(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addDays(4),
            'ends_at' => now()->addDays(4)->addHours(2),
        ]);
        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(TaskSpreadsheet::class)
            ->assertSuccessful()
            ->assertSee('Not assigned')
            ->assertDontSee('missing')
            ->assertSeeHtml("mountAction('editEventTask', { taskId: {$task->id}, focusCell:")
            ->mountAction('editEventTask', arguments: ['taskId' => $task->id])
            ->assertActionMounted('editEventTask');
    }

    public function test_list_groups_activities_by_event_identity_even_when_event_names_match(): void
    {
        $this->freezeTime();
        $templates = TaskTemplate::factory()->count(2)->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $firstEvent = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Sunday gathering',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2),
        ]);
        $secondEvent = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Sunday gathering',
            'starts_at' => now()->addDays(9),
            'ends_at' => now()->addDays(9)->addHours(2),
        ]);
        foreach ($templates as $template) {
            EventTask::factory()->create(['event_id' => $firstEvent->id, 'task_template_id' => $template->id]);
        }
        EventTask::factory()->create(['event_id' => $secondEvent->id, 'task_template_id' => $templates->first()->id]);
        $this->actingAs($this->admin);

        $component = Livewire::test(TaskSpreadsheet::class)->assertSuccessful();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new \DOMXPath($document);
        $groups = $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " wp-ss-event-group ")]');

        $this->assertCount(2, $groups);
        $this->assertSame(2, $xpath->query('.//button[contains(@class, "wp-ss-cell-edit")]', $groups->item(0))->length);
        $this->assertSame(1, $xpath->query('.//button[contains(@class, "wp-ss-cell-edit")]', $groups->item(1))->length);
        $this->assertSame('staffing-event-'.$firstEvent->id, $groups->item(0)->getAttribute('wire:key'));
        $this->assertSame('staffing-event-'.$secondEvent->id, $groups->item(1)->getAttribute('wire:key'));
        $this->assertFalse($groups->item(0)->hasAttribute('open'));
        $this->assertFalse($groups->item(1)->hasAttribute('open'));

        $component->set('tableSearch', 'No matching event')->assertSee('Nothing needs attention in this view.');
    }

    public function test_spreadsheet_refreshes_after_saving_task(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Reader',
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $assignee = User::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Carol',
            'display_name' => 'Carol',
        ]);
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(5)->addHours(2),
        ]);
        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => null,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(TaskSpreadsheet::class)
            ->assertSee('Not assigned')
            ->assertDontSee('Carol')
            ->callAction('editEventTask', data: [
                'task_template_id' => $template->id,
                'assigned_user_id' => $assignee->id,
                'sort_order' => 0,
            ], arguments: ['taskId' => $task->id])
            ->assertHasNoActionErrors()
            ->assertDontSee('Not assigned')
            ->assertSee('Carol');
    }

    public function test_edit_task_modal_can_remove_a_required_person(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(5)->addHours(2),
        ]);
        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(TaskSpreadsheet::class)
            ->callAction('editEventTask', data: [
                'assigned_user_id' => null,
            ], arguments: ['taskId' => $task->id])
            ->assertHasNoActionErrors();

        $this->assertNull($task->refresh()->assigned_user_id);
    }

    public function test_month_row_bands_alternate_by_month_and_row(): void
    {
        $rows = collect([
            ['event_date' => '2026-10-05', 'key' => 'a'],
            ['event_date' => '2026-10-12', 'key' => 'b'],
            ['event_date' => '2026-11-02', 'key' => 'c'],
            ['event_date' => '2026-11-09', 'key' => 'd'],
            ['event_date' => '2026-12-01', 'key' => 'e'],
        ]);

        $banded = TaskSpreadsheet::annotateMonthRowBands($rows);

        $this->assertSame([0, 0, 1, 1, 0], $banded->pluck('wp_month_band')->all());
        $this->assertSame([0, 1, 0, 1, 0], $banded->pluck('wp_row_parity')->all());
    }

    public function test_an_invited_person_shows_no_response_not_as_an_error(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Reader',
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $person = User::factory()->create([
            'church_id' => $this->church->id,
            'display_name' => 'Dana',
        ]);
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addDays(6),
            'ends_at' => now()->addDays(6)->addHours(2),
        ]);
        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $person->id,
        ]);
        EventTaskResponse::factory()->create([
            'church_id' => $this->church->id,
            'event_task_id' => $task->id,
            'assignee_user_id' => $person->id,
            'status' => AssignmentResponseStatus::Invited,
            'occurred_at' => now(),
        ]);

        $this->actingAs($this->admin);

        Livewire::test(TaskSpreadsheet::class)
            ->assertSuccessful()
            ->assertSee('Dana')
            ->assertSee('No response')
            ->assertDontSee('Not assigned');
    }

    public function test_an_availability_conflict_is_independent_of_the_response(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Reader',
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $person = User::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Evan',
            'display_name' => 'Evan',
        ]);
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addDays(7)->setTime(10, 0),
            'ends_at' => now()->addDays(7)->setTime(12, 0),
        ]);
        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $person->id,
        ]);
        Unavailability::factory()->create([
            'church_id' => $this->church->id,
            'user_id' => $person->id,
            'starts_on' => $event->starts_at->toDateString(),
            'ends_on' => $event->starts_at->toDateString(),
        ]);
        EventTaskResponse::factory()->create([
            'church_id' => $this->church->id,
            'event_task_id' => $task->id,
            'assignee_user_id' => $person->id,
            'status' => AssignmentResponseStatus::Confirmed,
            'occurred_at' => now(),
        ]);

        $matrix = app(TaskSpreadsheetBuilder::class)->build($this->church);
        $row = $matrix['rows']->first();
        $key = 'template_'.$template->id;

        $this->assertSame('confirmed', $row[$key.'_state']);
        $this->assertTrue($row[$key.'_conflict']);

        $this->actingAs($this->admin);

        Livewire::test(TaskSpreadsheet::class)
            ->assertSee('Evan')
            ->assertSee('Accepted')
            ->assertSee('Unavailable');
    }

    public function test_event_type_shortcuts_search_and_mobile_list_share_the_same_results(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $events = collect(['Morning service', 'Evening service', 'Prayer meeting'])->map(function (string $name, int $index) use ($template): Event {
            $event = Event::factory()->create([
                'church_id' => $this->church->id,
                'name' => $name,
                'starts_at' => now()->addDays($index + 1),
                'ends_at' => now()->addDays($index + 1)->addHour(),
            ]);
            EventTask::factory()->create([
                'event_id' => $event->id,
                'task_template_id' => $template->id,
            ]);

            return $event;
        });
        $this->actingAs($this->admin);

        $component = Livewire::test(TaskSpreadsheet::class)
            ->call('selectEventType', 'Morning service')
            ->assertSuccessful();

        $this->assertSame([$events[0]->id], $component->instance()->getTableRecords()->pluck('event_id')->all());
        $this->assertSame(['Morning service'], array_column($component->instance()->listRows(), 'event_name'));

        $component->set('tableFilters.events.names', ['Morning service', 'Evening service']);

        $this->assertSame([$events[0]->id, $events[1]->id], $component->instance()->getTableRecords()->pluck('event_id')->all());
        $this->assertSame(['Morning service', 'Evening service'], array_column($component->instance()->listRows(), 'event_name'));

        $component->set('tableSearch', 'EVENING');

        $this->assertSame([$events[1]->id], $component->instance()->getTableRecords()->pluck('event_id')->all());
        $this->assertSame(['Evening service'], array_column($component->instance()->listRows(), 'event_name'));

        $component->call('selectEventTypes', false);

        $this->assertCount(0, $component->instance()->getTableRecords());
        $this->assertSame([], $component->instance()->listRows());

        $component->set('tableSearch', '')->call('selectEventType');

        $this->assertCount(3, $component->instance()->getTableRecords());
        $this->assertCount(3, $component->instance()->listRows());
    }

    public function test_quick_ranges_clear_custom_dates_and_include_new_event_types(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00'));
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        foreach (['2026-10-25' => 'Sunday service', '2026-11-15' => 'Prayer meeting'] as $date => $name) {
            $event = Event::factory()->create([
                'church_id' => $this->church->id,
                'name' => $name,
                'starts_at' => $date.' 10:00',
                'ends_at' => $date.' 12:00',
            ]);
            EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $template->id]);
        }
        $this->actingAs($this->admin);

        $component = Livewire::test(TaskSpreadsheet::class)->call('setRange', 'this_month');

        $this->assertSame(['Sunday service'], array_column($component->instance()->listRows(), 'event_name'));

        $component->set('tableFilters.custom_dates', ['from' => '2026-10-24', 'until' => '2026-10-26'])
            ->assertSet('range', 'custom')
            ->call('setRange', 'next_month')
            ->call('selectEventType', 'Prayer meeting')
            ->assertSet('range', 'next_month')
            ->assertSet('customFrom', null)
            ->assertSet('customUntil', null);

        $this->assertSame(['Prayer meeting'], array_column($component->instance()->listRows(), 'event_name'));
    }

    public function test_week_number_uses_iso_week_at_the_year_boundary(): void
    {
        $this->travelTo(Carbon::parse('2026-12-28 12:00'));
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => '2027-01-01 10:00',
            'ends_at' => '2027-01-01 12:00',
        ]);
        EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $template->id]);

        $row = app(TaskSpreadsheetBuilder::class)->build($this->church)['rows']->first();

        $this->assertSame(53, $row['event_week']);
        $this->assertSame('1 Jan 2027', $row['event_calendar_label']);
        $this->assertSame('Friday', $row['event_day_label']);
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function spreadsheetLocales(): array
    {
        return [
            'English' => ['en', 'Search events or people', 'Week 43', 'Sunday'],
            'Swedish' => ['sv', 'Sök evenemang eller personer', 'Vecka 43', 'söndag'],
            'Arabic' => ['ar', 'ابحث عن فعاليات أو أشخاص', 'الأسبوع 43', 'الأحد'],
        ];
    }

    #[DataProvider('spreadsheetLocales')]
    public function test_toolbar_and_calendar_labels_use_the_interface_language(string $locale, string $search, string $week, string $day): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00'));
        app()->setLocale($locale);
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Gudstjänst',
            'starts_at' => '2026-10-25 10:00',
            'ends_at' => '2026-10-25 12:00',
        ]);
        EventTask::factory()->create(['event_id' => $event->id, 'task_template_id' => $template->id]);
        $this->actingAs($this->admin);

        Livewire::test(TaskSpreadsheet::class)
            ->assertSuccessful()
            ->assertSee($search)
            ->assertSee($week)
            ->assertSee($day)
            ->assertSee('Gudstjänst');
    }

    public function test_search_finds_assignee_names_without_matching_html_markup(): void
    {
        $person = User::factory()->create(['church_id' => $this->church->id, 'display_name' => 'Grace & Hope']);
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::NotUsed,
        ]);
        $event = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);
        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $person->id,
        ]);
        $this->actingAs($this->admin);

        $component = Livewire::test(TaskSpreadsheet::class)->set('tableSearch', 'grace & hope');

        $this->assertSame([$event->id], $component->instance()->getTableRecords()->pluck('event_id')->all());
        $this->assertCount(1, $component->instance()->listRows());

        $component->set('tableSearch', 'wp-avatar');

        $this->assertCount(0, $component->instance()->getTableRecords());
        $this->assertSame([], $component->instance()->listRows());
    }

    public function test_tasks_by_event_page_requires_its_permission(): void
    {
        $role = Role::findOrCreate('Sunday Wizard', 'web');
        $role->givePermissionTo(['events.view', 'events.update']);
        $editor = User::factory()->create(['church_id' => $this->church->id]);
        $editor->assignRole('Sunday Wizard');

        $this->actingAs($editor)
            ->get(EventResource::getUrl('task-spreadsheet'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(EventResource::getUrl('task-spreadsheet'))
            ->assertOk();
    }

    public function test_existing_roles_that_edit_events_keep_tasks_by_event(): void
    {
        Permission::query()->where('name', 'events.tasks_by_event')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $editorRole = Role::findOrCreate('Sunday Wizard', 'web');
        $editorRole->givePermissionTo(['events.view', 'events.update']);

        (require base_path('database/migrations/2026_11_01_062412_add_tasks_by_event_permission.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($editorRole->fresh()->hasPermissionTo('events.tasks_by_event'));
        $this->assertTrue(Role::findByName('Church Admin', 'web')->hasPermissionTo('events.tasks_by_event'));
        $this->assertFalse(Role::findByName('Member', 'web')->hasPermissionTo('events.tasks_by_event'));
    }
}
