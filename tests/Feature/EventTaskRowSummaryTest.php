<?php

namespace Tests\Feature;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\FieldUsage;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TeamType;
use App\Models\Unavailability;
use App\Models\User;
use App\Services\EventTaskRowSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTaskRowSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_lists_filled_fields_and_skips_excluded_ones(): void
    {
        $church = Church::factory()->create();
        app(ChurchModuleManager::class)->enable($church, 'teams');
        app(ChurchModuleManager::class)->enable($church, 'bible_verses');
        app(ChurchModuleManager::class)->enable($church, 'pictures');

        $person = User::factory()->create([
            'church_id' => $church->id,
            'display_name' => 'Alex',
        ]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'person_usage' => FieldUsage::Optional,
            'song_usage' => FieldUsage::Optional,
            'description_usage' => FieldUsage::Optional,
            'bible_usage' => FieldUsage::Optional,
            'picture_usage' => FieldUsage::Optional,
        ]);

        $event = Event::factory()->create(['church_id' => $church->id]);
        $task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'name' => 'Opening',
            'description' => 'Welcome everyone',
            'song' => 'Amazing Grace',
            'assigned_user_id' => $person->id,
            'duration' => 10,
            'bible_passages' => [
                ['bible' => 'kjv', 'ref' => 'JOHN.3.16', 'label' => 'John 3:16'],
            ],
        ]);

        $summary = app(EventTaskRowSummary::class);
        $bits = $summary->filledBits($task->fresh(['assignedUser', 'template', 'catalogSong']), $event);
        $html = $summary->html($task->fresh([
            'assignedUser',
            'template',
            'catalogSong',
            'sharedFiles',
            'pictures',
        ]), $event)->toHtml();

        $this->assertContains('Welcome everyone', $bits);
        $this->assertStringContainsString('Welcome everyone', $html);
        $this->assertStringNotContainsString('Desc: ', $html);
        $this->assertContains('Song: Amazing Grace', $bits);
        $this->assertContains('Alex', $bits);
        $this->assertStringNotContainsString('Person: ', $html);
        $this->assertStringNotContainsString('John 3:16', $html);
        $this->assertStringNotContainsString('Opening', implode(' ', $bits));
    }

    public function test_summary_flags_missing_required_and_unavailability(): void
    {
        $church = Church::factory()->create();
        app(ChurchModuleManager::class)->enable($church, 'teams');
        app(ChurchModuleManager::class)->enable($church, 'unavailability');

        $person = User::factory()->create([
            'church_id' => $church->id,
            'display_name' => 'Busy Pat',
        ]);

        $type = TeamType::factory()->create(['church_id' => $church->id]);
        $team = Team::factory()->create([
            'church_id' => $church->id,
            'team_type_id' => $type->id,
            'name' => 'Band',
        ]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'person_usage' => FieldUsage::Required,
            'team_usage' => FieldUsage::Required,
            'team_type_id' => $type->id,
            'song_usage' => FieldUsage::Required,
        ]);

        $event = Event::factory()->create([
            'church_id' => $church->id,
            'starts_at' => now()->addDay()->setTime(10, 0),
        ]);

        Unavailability::factory()->create([
            'church_id' => $church->id,
            'user_id' => $person->id,
            'team_id' => null,
            'starts_on' => $event->starts_at->toDateString(),
            'ends_on' => $event->starts_at->toDateString(),
        ]);

        $missingTask = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => null,
            'assigned_team_id' => null,
            'song' => null,
            'song_id' => null,
        ]);

        $conflictTask = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $person->id,
            'assigned_team_id' => $team->id,
            'song' => 'Hymn',
        ]);

        $summary = app(EventTaskRowSummary::class);

        $missing = $summary->problems($missingTask->fresh([
            'template',
            'assignedUser',
            'assignedTeam',
            'sharedFiles',
            'pictures',
        ]), $event);

        $this->assertContains('Missing: Person', $missing);
        $this->assertContains('Missing: Team', $missing);
        $this->assertContains('Missing: Song', $missing);

        $conflicts = $summary->problems($conflictTask->fresh([
            'template',
            'assignedUser',
            'assignedTeam',
            'sharedFiles',
            'pictures',
        ]), $event);

        $this->assertContains('Busy Pat is unavailable', $conflicts);
        $this->assertStringContainsString('wp-task-problem', $summary->html($conflictTask, $event)->toHtml());
        $this->assertStringContainsString('Busy Pat is unavailable', $summary->html($conflictTask, $event)->toHtml());
    }
}
