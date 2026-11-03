<?php

namespace Tests\Feature;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\Song;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TeamType;
use App\Models\User;
use App\Services\Demo\DemoSnapshotBuilder;
use App\Services\Demo\DemoSnapshotLoader;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    private User $admin;

    private Event $upcoming;

    private EventTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00'));

        $this->church = Church::factory()->create(['name' => 'Pingstkyrkan Exempel', 'locale' => 'sv']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Karin Realname',
            'email' => 'karin@real-church.se',
            'phone' => '+46701234567',
            'telegram_chat_id' => '998877',
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        $modules = app(ChurchModuleManager::class);
        $modules->enable($this->church, 'messaging', ['email' => ['host' => 'smtp.real-church.se', 'password' => 'smtp-secret']]);
        $modules->enable($this->church, 'teams');

        $teamType = TeamType::factory()->create(['church_id' => $this->church->id, 'name' => 'Lovsångsteam']);
        Team::factory()->create(['church_id' => $this->church->id, 'team_type_id' => $teamType->id, 'name' => 'Lovsångsteam']);
        $template = TaskTemplate::factory()->create(['church_id' => $this->church->id, 'name' => 'Predikan']);
        $song = Song::factory()->create(['church_id' => $this->church->id, 'name' => 'O prisa högt']);

        $this->upcoming = Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Gudstjänst',
            'description' => 'Person: Karin Realname',
            'starts_at' => '2026-11-08 11:00:00',
            'ends_at' => '2026-11-08 13:00:00',
        ]);
        $this->task = EventTask::factory()->create([
            'event_id' => $this->upcoming->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $this->admin->id,
            'name' => 'Predikan',
            'description' => "Pålysningar\r\n",
            'song_id' => $song->id,
            'starts_at' => '11:15:00',
        ]);

        Event::factory()->create([
            'church_id' => $this->church->id,
            'name' => 'Gudstjänst',
            'starts_at' => '2025-01-05 11:00:00',
            'ends_at' => '2025-01-05 13:00:00',
        ]);
    }

    public function test_snapshot_holds_no_personal_data_secrets_or_untranslated_text(): void
    {
        $result = $this->build();
        $json = json_encode($result['snapshot']);

        foreach (['Karin Realname', 'karin@real-church.se', '+46701234567', '998877', 'smtp.real-church.se', 'smtp-secret', 'O prisa högt', 'Pingstkyrkan'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }

        $tables = $result['snapshot']['tables'];
        $this->assertSame('admin@example.com', $tables['users'][0]['email']);
        $this->assertSame('Example Community Church', $tables['churches'][0]['name']);
        $this->assertSame('en', $tables['churches'][0]['locale']);
        $this->assertSame(['Sunday Service'], array_column($tables['events'], 'name'), 'events older than the history window are left out');
        $this->assertNull($tables['events'][0]['description'], 'untranslated free text is dropped');
        $this->assertSame('Announcements', $tables['event_tasks'][0]['description']);
        $this->assertArrayHasKey(DemoSnapshotBuilder::hashFor('Person: Karin Realname'), $result['missing']);
        $this->assertSame([], $result['missing_names']);
    }

    public function test_snapshot_turns_off_locked_messaging_modules_and_adds_ntfy(): void
    {
        $modules = array_column($this->build()['snapshot']['tables']['church_modules'], 'module_key');

        $this->assertContains('teams', $modules);
        $this->assertContains('messaging_ntfy', $modules);
        $this->assertNotContains('messaging', $modules);
    }

    public function test_snapshot_replaces_used_songs_with_hymns(): void
    {
        $tables = $this->build()['snapshot']['tables'];

        $this->assertSame('Amazing Grace', $tables['songs'][0]['name']);
        $this->assertSame($tables['songs'][0]['id'], $tables['event_tasks'][0]['song_id']);
    }

    public function test_untranslated_names_are_reported(): void
    {
        $result = app(DemoSnapshotBuilder::class)->build($this->church->id, CarbonImmutable::today(), [], $this->hymns());

        $this->assertContains('Gudstjänst', $result['missing_names']);
    }

    public function test_loading_moves_dates_forward_by_whole_weeks_and_keeps_task_times(): void
    {
        $snapshot = $this->build()['snapshot'];

        app(DemoSnapshotLoader::class)->load($snapshot, CarbonImmutable::parse('2026-11-19'));

        $event = DB::table('events')->where('id', $this->upcoming->id)->first();
        $this->assertSame('2026-11-22 11:00:00', $event->starts_at, '16 days later rounds to a 14-day shift');
        $this->assertSame('11:15:00', DB::table('event_tasks')->where('id', $this->task->id)->value('starts_at'));
    }

    public function test_loaded_accounts_share_the_demo_password_and_get_ntfy_topics(): void
    {
        config(['demo.password' => 'open-sesame']);

        app(DemoSnapshotLoader::class)->load($this->build()['snapshot'], CarbonImmutable::today());

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('open-sesame', $admin->password));
        $this->assertNotEmpty($admin->ntfy_topic);
        $this->assertTrue($admin->hasRole('Church Admin'));
        $this->assertDatabaseHas('message_templates', ['church_id' => $this->church->id, 'key' => 'set_password']);
    }

    public function test_shift_rounds_to_whole_weeks(): void
    {
        $anchor = CarbonImmutable::parse('2026-11-03');

        $this->assertSame(0, DemoSnapshotLoader::shiftInDays($anchor, $anchor->addDays(3)));
        $this->assertSame(7, DemoSnapshotLoader::shiftInDays($anchor, $anchor->addDays(4)));
        $this->assertSame(364, DemoSnapshotLoader::shiftInDays($anchor, $anchor->addDays(365)));
    }

    public function test_reset_refuses_to_run_outside_demo_mode(): void
    {
        $this->artisan('demo:reset')->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    /**
     * @return array{snapshot: array<string, mixed>, missing: array<string, string>, missing_names: array<string, string>}
     */
    private function build(): array
    {
        $translations = [];

        foreach (['Pingstkyrkan Exempel' => 'Example Community Church', 'Gudstjänst' => 'Sunday Service', 'Lovsångsteam' => 'Worship team', 'Predikan' => 'Sermon', 'Pålysningar' => 'Announcements'] as $swedish => $english) {
            $translations[DemoSnapshotBuilder::hashFor($swedish)] = $english;
        }

        return app(DemoSnapshotBuilder::class)->build($this->church->id, CarbonImmutable::today(), $translations, $this->hymns());
    }

    /**
     * @return list<array{name: string, author: string}>
     */
    private function hymns(): array
    {
        return [['name' => 'Amazing Grace', 'author' => 'John Newton'], ['name' => 'Holy, Holy, Holy', 'author' => 'Reginald Heber']];
    }
}
