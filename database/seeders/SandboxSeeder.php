<?php

namespace Database\Seeders;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\AssignmentResponseMethod;
use App\Enums\AssignmentResponseStatus;
use App\Enums\FieldUsage;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskResponse;
use App\Models\Language;
use App\Models\Song;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TeamType;
use App\Models\Unavailability;
use App\Models\User;
use App\Support\SeedsDefaultTeamTypes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Synthetic, shareable fixture for the isolated redesign sandbox. Never contains real people.
 *
 * Scenarios covered: several events across a month boundary, teams with leaders, three languages,
 * an unfilled (vacant) required role, a person assigned while unavailable (conflict), a team-assigned
 * task, songs, and a second church for tenancy checks. Accept/decline states are not modelled yet (Phase 1).
 */
class SandboxSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['sandbox', 'testing'])) {
            throw new RuntimeException('SandboxSeeder only runs in the sandbox or testing environment.');
        }

        $this->call(DatabaseSeeder::class);

        $church = Church::query()->where('slug', 'example-church')->firstOrFail();
        $modules = app(ChurchModuleManager::class);
        foreach (['unavailability', 'song_list', 'abilities'] as $module) {
            $modules->enable($church, $module);
        }

        $languages = Language::query()->pluck('id', 'iso');
        $volunteers = $this->volunteers($church, $languages->all());
        $teams = $this->teams($church, $volunteers);
        $templates = $this->templates($church);
        $songs = $this->songs($church);

        $this->events($church, $templates, $volunteers, $teams, $songs);
        $this->responses($church);
        $this->secondChurch();
    }

    /**
     * @param  array<string, int>  $languageIds
     * @return array<string, User>
     */
    private function volunteers(Church $church, array $languageIds): array
    {
        $people = [
            'anna' => ['Anna Lindqvist', 'sv'],
            'ben' => ['Ben Carter', 'en'],
            'clara' => ['Clara Svensson', 'sv'],
            'david' => ['David Okafor', 'en'],
            'emma' => ['Emma Johansson', 'sv'],
            'farid' => ['Farid Haddad', 'ar'],
            'grace' => ['Grace Mwangi', 'en'],
            'henrik' => ['Henrik Berg', 'sv'],
        ];

        $users = [];
        foreach ($people as $key => [$name, $iso]) {
            $user = User::factory()->create([
                'church_id' => $church->id,
                'name' => $name,
                'display_name' => explode(' ', $name)[0],
                'email' => "{$key}@example.test",
                'password' => Hash::make('password'),
                'language_id' => $languageIds[$iso],
            ]);
            RolesAndPermissionsSeeder::assignDefaultRoles($user, 'Member');
            $users[$key] = $user;
        }

        return $users;
    }

    /**
     * @param  array<string, User>  $volunteers
     * @return array<string, Team>
     */
    private function teams(Church $church, array $volunteers): array
    {
        SeedsDefaultTeamTypes::for($church);

        $typeId = fn (string $name): int => TeamType::query()->where('church_id', $church->id)->where('name', $name)->firstOrFail()->id;

        $band = Team::factory()->create(['church_id' => $church->id, 'name' => 'Worship band', 'description' => 'Sunday music team.', 'team_type_id' => $typeId('Music team')]);
        $tech = Team::factory()->create(['church_id' => $church->id, 'name' => 'Tech team', 'description' => 'Sound, slides and streaming.', 'team_type_id' => $typeId('Tech team')]);
        $greeters = Team::factory()->create(['church_id' => $church->id, 'name' => 'Greeters', 'description' => 'Welcome at the door.', 'team_type_id' => $typeId('Greeter team')]);

        $band->members()->attach([
            $volunteers['anna']->id => ['is_leader' => true],
            $volunteers['ben']->id => ['is_leader' => false],
            $volunteers['clara']->id => ['is_leader' => false],
        ]);
        $tech->members()->attach([
            $volunteers['david']->id => ['is_leader' => true],
            $volunteers['emma']->id => ['is_leader' => false],
        ]);
        $greeters->members()->attach([
            $volunteers['farid']->id => ['is_leader' => false],
            $volunteers['grace']->id => ['is_leader' => false],
        ]);

        return ['band' => $band, 'tech' => $tech, 'greeters' => $greeters];
    }

    /**
     * @return array<string, TaskTemplate>
     */
    private function templates(Church $church): array
    {
        $existing = TaskTemplate::query()->where('church_id', $church->id)->get()->keyBy('name')->all();

        $existing['Worship leader'] = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'name' => 'Worship leader',
            'default_order' => 5,
            'default_duration' => 30,
            'person_usage' => FieldUsage::Required,
            'show_in_spreadsheet' => true,
        ]);
        $existing['Sound'] = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'name' => 'Sound',
            'default_order' => 6,
            'default_duration' => 0,
            'person_usage' => FieldUsage::Required,
            'show_in_spreadsheet' => true,
        ]);
        $existing['Greeting'] = TaskTemplate::factory()->create([
            'church_id' => $church->id,
            'name' => 'Greeting',
            'default_order' => 7,
            'default_duration' => 0,
            'person_usage' => FieldUsage::NotUsed,
            'team_usage' => FieldUsage::Required,
            'show_in_spreadsheet' => true,
        ]);

        return $existing;
    }

    /**
     * @return list<Song>
     */
    private function songs(Church $church): array
    {
        return array_map(
            fn (string $name): Song => Song::factory()->create(['church_id' => $church->id, 'name' => $name, 'name2' => null, 'author' => 'Sandbox Author', 'description' => null]),
            ['Morning Light', 'Hold Me Fast', 'Open Doors', 'Quiet Waters'],
        );
    }

    /**
     * @param  array<string, TaskTemplate>  $templates
     * @param  array<string, User>  $volunteers
     * @param  array<string, Team>  $teams
     * @param  list<Song>  $songs
     */
    private function events(Church $church, array $templates, array $volunteers, array $teams, array $songs): void
    {
        $churchAdmin = User::query()->where('email', 'calum@example.test')->firstOrFail();
        $firstSunday = now()->startOfMonth()->addMonth()->subWeeks(2)->next('Sunday')->setTime(10, 0);

        $leaders = ['anna', 'ben', 'clara', 'anna', 'ben', 'clara'];
        $sound = ['david', 'emma', null, 'david', 'emma', 'david'];

        foreach ($leaders as $index => $leaderKey) {
            $startsAt = $firstSunday->copy()->addWeeks($index);

            $event = Event::factory()->create([
                'church_id' => $church->id,
                'name' => 'Sunday gathering',
                'description' => null,
                'starts_at' => $startsAt,
            ]);

            $this->task($event, $templates['Welcome'], 10, userId: $volunteers['grace']->id);
            $this->task($event, $templates['Worship leader'], 20, userId: $volunteers[$leaderKey]->id, song: $songs[$index % count($songs)]);
            $this->task($event, $templates['Opening song'], 30, song: $songs[$index % count($songs)]);
            $this->task($event, $templates['Sermon'], 40, userId: ($index % 2 === 0 ? $churchAdmin->id : ($index === 5 ? $volunteers['farid']->id : $volunteers['henrik']->id)));
            $this->task($event, $templates['Sound'], 50, userId: $sound[$index] === null ? null : $volunteers[$sound[$index]]->id);
            $this->task($event, $templates['Greeting'], 60, teamId: $teams['greeters']->id);
        }

        $conflictDate = $firstSunday->copy()->addWeeks(1);
        Unavailability::factory()->create([
            'church_id' => $church->id,
            'user_id' => $volunteers['ben']->id,
            'starts_on' => $conflictDate->copy()->subDay()->toDateString(),
            'ends_on' => $conflictDate->copy()->addDay()->toDateString(),
            'note' => 'Away (synthetic conflict: still assigned as worship leader)',
        ]);
        Unavailability::factory()->create([
            'church_id' => $church->id,
            'user_id' => $volunteers['emma']->id,
            'starts_on' => $firstSunday->copy()->addWeeks(2)->subDay()->toDateString(),
            'ends_on' => $firstSunday->copy()->addWeeks(2)->addDay()->toDateString(),
            'note' => 'Holiday (synthetic: unavailable and correctly not assigned)',
        ]);
    }

    /**
     * Synthetic invitation history so every response state is visible in the sandbox.
     */
    private function responses(Church $church): void
    {
        $coordinator = User::query()->where('email', 'calum@example.test')->firstOrFail();
        $events = Event::query()->where('church_id', $church->id)->orderBy('starts_at')->get()->values();

        $plan = [
            0 => ['Worship leader' => [AssignmentResponseStatus::Confirmed, AssignmentResponseMethod::Replied], 'Sound' => [AssignmentResponseStatus::Invited, null], 'Sermon' => [AssignmentResponseStatus::Confirmed, AssignmentResponseMethod::Verbal]],
            1 => ['Worship leader' => [AssignmentResponseStatus::Invited, null], 'Sound' => [AssignmentResponseStatus::Declined, AssignmentResponseMethod::Replied]],
            2 => ['Worship leader' => [AssignmentResponseStatus::Invited, null]],
            3 => ['Worship leader' => [AssignmentResponseStatus::Confirmed, AssignmentResponseMethod::Replied], 'Sound' => [AssignmentResponseStatus::Invited, null]],
        ];

        foreach ($plan as $index => $byTemplate) {
            $event = $events[$index];

            foreach ($byTemplate as $templateName => [$status, $method]) {
                $task = EventTask::query()
                    ->where('event_id', $event->id)
                    ->whereHas('template', fn ($query) => $query->where('name', $templateName))
                    ->whereNotNull('assigned_user_id')
                    ->firstOrFail();

                EventTaskResponse::query()->create([
                    'church_id' => $church->id,
                    'event_task_id' => $task->id,
                    'assignee_user_id' => $task->assigned_user_id,
                    'responder_user_id' => $status === AssignmentResponseStatus::Invited ? null : $task->assigned_user_id,
                    'recorded_by_user_id' => $method === AssignmentResponseMethod::Verbal || $status === AssignmentResponseStatus::Invited ? $coordinator->id : null,
                    'status' => $status,
                    'method' => $method,
                    'requested_at' => $event->starts_at->copy()->subDays(10),
                    'occurred_at' => $event->starts_at->copy()->subDays($status === AssignmentResponseStatus::Invited ? 10 : 8),
                ]);
            }
        }
    }

    private function task(Event $event, TaskTemplate $template, int $order, ?int $userId = null, ?int $teamId = null, ?Song $song = null): void
    {
        EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $userId,
            'assigned_team_id' => $teamId,
            'song_id' => $song?->id,
            'song' => $song?->name,
            'duration' => $template->default_duration,
            'sort_order' => $order,
        ]);
    }

    private function secondChurch(): void
    {
        $other = Church::factory()->create(['name' => 'Second Fellowship', 'slug' => 'second-fellowship']);
        RolesAndPermissionsSeeder::seedForChurch($other);

        $admin = User::factory()->create([
            'church_id' => $other->id,
            'name' => 'Second Admin',
            'display_name' => 'Second',
            'email' => 'admin@second.example.test',
            'password' => Hash::make('password'),
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        Event::factory()->create(['church_id' => $other->id, 'name' => 'Second Fellowship service', 'description' => null]);
    }
}
