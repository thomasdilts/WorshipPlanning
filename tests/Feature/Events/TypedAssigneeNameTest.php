<?php

namespace Tests\Feature\Events;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentState;
use App\Enums\FieldUsage;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\TasksRelationManager;
use App\Filament\Resources\TaskTemplates\Pages\EditTaskTemplate;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\TeamType;
use App\Models\User;
use App\Services\AssignmentStateResolver;
use App\Services\EventStaffingSummary;
use App\Services\EventTaskRowSummary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TypedAssigneeNameTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $admin;

    protected Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($this->church, 'teams');

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        $this->event = Event::factory()->create(['church_id' => $this->church->id]);

        app(CurrentChurch::class)->set($this->church);
        $this->actingAs($this->admin);
    }

    public function test_template_allows_typed_names_only_while_the_field_is_used(): void
    {
        $template = $this->template(allowPerson: false, allowTeam: false);

        Livewire::test(EditTaskTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm([
                'allow_person_text' => true,
                'team_usage' => FieldUsage::Optional->value,
                'team_type_id' => TeamType::factory()->create(['church_id' => $this->church->id])->id,
                'allow_team_text' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($template->fresh()->allow_person_text);
        $this->assertTrue($template->fresh()->allow_team_text);

        Livewire::test(EditTaskTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm(['person_usage' => FieldUsage::NotUsed->value, 'team_usage' => FieldUsage::NotUsed->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($template->fresh()->allow_person_text);
        $this->assertFalse($template->fresh()->allow_team_text);
    }

    public function test_a_typed_person_name_fills_a_required_task_without_a_reply_status(): void
    {
        $template = $this->template(allowPerson: true, allowTeam: true, person: FieldUsage::Required);

        $this->createTask($template, ['person_text' => '  Guest speaker Anna  ', 'team_text' => 'Visiting choir']);

        $task = EventTask::query()->sole();
        $this->assertSame('Guest speaker Anna', $task->person_text);
        $this->assertSame('Visiting choir', $task->team_text);

        $summary = app(EventTaskRowSummary::class);
        $this->assertContains('Guest speaker Anna', $summary->filledBits($task, $this->event));
        $this->assertContains(__('Team: ').'Visiting choir', $summary->filledBits($task, $this->event));
        $this->assertSame([], $summary->problems($task, $this->event));
        $this->assertSame(AssignmentState::Named, app(AssignmentStateResolver::class)->for($task));

        $staffing = app(EventStaffingSummary::class)->for($this->event->fresh());
        $this->assertSame(0, $staffing['vacant']);
        $this->assertSame(1, $staffing['confirmed']);
    }

    public function test_typed_names_are_dropped_when_not_allowed_or_when_someone_is_picked(): void
    {
        $notAllowed = $this->template(allowPerson: false, allowTeam: false);
        $this->createTask($notAllowed, ['person_text' => 'Someone', 'team_text' => 'Some team']);

        $allowed = $this->template(allowPerson: true, allowTeam: false);
        $member = User::factory()->create(['church_id' => $this->church->id]);
        $this->createTask($allowed, ['person_text' => 'Someone', 'assigned_user_id' => $member->id]);

        $this->assertSame(0, EventTask::query()->whereNotNull('person_text')->orWhereNotNull('team_text')->count());
        $this->assertSame($member->id, EventTask::query()->where('task_template_id', $allowed->id)->value('assigned_user_id'));
    }

    protected function template(bool $allowPerson, bool $allowTeam, FieldUsage $person = FieldUsage::Optional): TaskTemplate
    {
        return TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'person_usage' => $person,
            'allow_person_text' => $allowPerson,
            'team_usage' => FieldUsage::Optional,
            'allow_team_text' => $allowTeam,
            'description_usage' => FieldUsage::NotUsed,
            'special_needs_usage' => FieldUsage::NotUsed,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createTask(TaskTemplate $template, array $data): void
    {
        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $this->event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('create', data: [
                'task_template_id' => $template->id,
                'sort_order' => 1,
                ...$data,
            ])
            ->assertHasNoTableActionErrors();
    }
}
