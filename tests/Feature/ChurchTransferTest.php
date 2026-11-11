<?php

namespace Tests\Feature;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Filament\Pages\ExportChurchData;
use App\Filament\Resources\Churches\Pages\ManageChurches;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\MessageTemplate;
use App\Models\Picture;
use App\Models\Song;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TeamType;
use App\Models\User;
use App\Services\ChurchTransfer\ChurchExporter;
use App\Services\ChurchTransfer\ChurchImporter;
use App\Services\ChurchTransfer\ChurchTables;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use ZipArchive;

class ChurchTransferTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    private User $admin;

    private EventTask $task;

    private string $zipPath;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->zipPath = sys_get_temp_dir().'/church-export-'.uniqid().'.zip';

        $this->church = Church::factory()->create(['slug' => 'grace-church', 'logo_path' => 'churches/1/logo/logo.png']);
        RolesAndPermissionsSeeder::seedForChurch($this->church);
        Storage::disk('public')->put($this->church->logo_path, 'logo-bytes');

        $this->admin = User::factory()->create([
            'church_id' => $this->church->id,
            'email' => 'anna@grace.test',
            'password' => Hash::make('anna-secret'),
            'avatar_path' => null,
            'spreadsheet_preferences' => [(string) $this->church->id => ['density' => 'compact']],
        ]);
        DB::table('users')->where('id', $this->admin->id)->update(['avatar_path' => "churches/{$this->church->id}/avatars/anna.jpg"]);
        Storage::disk('public')->put("churches/{$this->church->id}/avatars/anna.jpg", 'avatar-bytes');
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        $teamType = TeamType::factory()->create(['church_id' => $this->church->id]);
        $team = Team::factory()->create(['church_id' => $this->church->id, 'team_type_id' => $teamType->id]);
        $team->members()->attach($this->admin->id, ['is_leader' => true]);
        $template = TaskTemplate::factory()->create(['church_id' => $this->church->id]);
        $song = Song::factory()->create(['church_id' => $this->church->id]);
        $event = Event::factory()->create(['church_id' => $this->church->id]);
        $this->task = EventTask::factory()->create([
            'event_id' => $event->id,
            'task_template_id' => $template->id,
            'assigned_user_id' => $this->admin->id,
            'assigned_team_id' => $team->id,
            'song_id' => $song->id,
        ]);

        $picture = Picture::factory()->create(['church_id' => $this->church->id, 'path' => "churches/{$this->church->id}/pictures/cross.jpg"]);
        Storage::disk('public')->put($picture->path, 'picture-bytes');
        DB::table('event_task_picture')->insert(['event_task_id' => $this->task->id, 'picture_id' => $picture->id, 'sort_order' => 1]);

        $messageTemplate = MessageTemplate::query()->create([
            'church_id' => $this->church->id,
            'name' => 'Telegram confirmation',
            'key' => 'telegram_confirmation',
            'subject' => 'Hi',
            'body' => 'Hi',
        ]);

        $modules = app(ChurchModuleManager::class);
        $modules->updateSettings($this->church, 'messaging', ['email' => ['host' => 'smtp.grace.test', 'password' => $modules->encryptSecret('smtp-secret')]]);
        $modules->updateSettings($this->church, 'messaging_telegram', [
            'bot_token' => $modules->encryptSecret('bot-secret'),
            'webhook_secret' => 'hook-secret',
            'confirmation_template_id' => $messageTemplate->id,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->zipPath)) {
            unlink($this->zipPath);
        }

        parent::tearDown();
    }

    public function test_every_database_table_is_either_exported_or_deliberately_left_out(): void
    {
        $tables = array_column(Schema::getTables(), 'name');
        $listed = [...array_keys(ChurchTables::TABLES), ...ChurchTables::NOT_EXPORTED];

        $this->assertSame([], array_values(array_diff($tables, $listed)), 'Add new tables to ChurchTables::TABLES or ChurchTables::NOT_EXPORTED');
    }

    public function test_import_creates_a_separate_church_with_all_rows_relations_and_files(): void
    {
        app(ChurchExporter::class)->export($this->church, $this->zipPath, includeSecrets: false);
        $this->freeEmailsAsIfImportingOnAnotherServer();
        $imported = app(ChurchImporter::class)->import($this->zipPath)['church'];

        $this->assertNotSame($this->church->id, $imported->id);
        $this->assertSame('grace-church-2', $imported->slug, 'slugs are unique across the server');
        $this->assertSame('logo-bytes', Storage::disk('public')->get($imported->logo_path));

        $anna = User::query()->where('church_id', $imported->id)->where('email', 'anna@grace.test')->firstOrFail();
        $this->assertNotSame($this->admin->id, $anna->id);
        $this->assertTrue(Hash::check('anna-secret', $anna->password), 'people keep their password');
        $this->assertSame("churches/{$imported->id}/avatars/anna.jpg", $anna->avatar_path);
        $this->assertSame('avatar-bytes', Storage::disk('public')->get($anna->avatar_path));
        $this->assertSame([(string) $imported->id => ['density' => 'compact']], $anna->spreadsheet_preferences);

        app(PermissionRegistrar::class)->setPermissionsTeamId($imported->id);
        $this->assertTrue($anna->hasRole('Church Admin'));
        $this->assertTrue($anna->can('church.export'));

        $task = EventTask::query()->whereHas('event', fn ($query) => $query->where('church_id', $imported->id))->firstOrFail();
        $this->assertSame($anna->id, $task->assigned_user_id);
        $this->assertSame($imported->id, $task->assignedTeam->church_id);
        $this->assertSame($imported->id, $task->catalogSong->church_id);
        $this->assertSame($imported->id, $task->template->church_id);
        $this->assertTrue($anna->ledTeams()->exists());

        $pictureId = DB::table('event_task_picture')->where('event_task_id', $task->id)->value('picture_id');
        $this->assertSame('picture-bytes', Storage::disk('public')->get(Picture::query()->findOrFail($pictureId)->path));

        $this->assertSame(1, User::query()->where('church_id', $this->church->id)->count(), 'the original church is untouched');
        $this->assertSame($this->admin->id, $this->task->fresh()->assigned_user_id);
    }

    public function test_secrets_are_left_out_unless_asked_for(): void
    {
        app(ChurchExporter::class)->export($this->church, $this->zipPath, includeSecrets: false);
        $contents = $this->zipContents();

        foreach (['smtp-secret', 'bot-secret', 'hook-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $contents);
        }

        $this->freeEmailsAsIfImportingOnAnotherServer();
        $imported = app(ChurchImporter::class)->import($this->zipPath)['church'];
        $settings = app(ChurchModuleManager::class)->settings($imported, 'messaging');
        $this->assertSame('smtp.grace.test', $settings['email']['host']);
        $this->assertSame('', $settings['email']['password']);
    }

    public function test_included_secrets_are_re_encrypted_and_template_settings_follow_the_new_templates(): void
    {
        app(ChurchExporter::class)->export($this->church, $this->zipPath, includeSecrets: true);
        $this->assertStringContainsString('bot-secret', $this->zipContents(), 'the export carries plain text, not this server\'s encryption');

        $this->freeEmailsAsIfImportingOnAnotherServer();
        $imported = app(ChurchImporter::class)->import($this->zipPath)['church'];
        $modules = app(ChurchModuleManager::class);
        $telegram = $modules->settings($imported, 'messaging_telegram');

        $this->assertNotSame('bot-secret', $telegram['bot_token'], 'stored encrypted');
        $this->assertSame('bot-secret', $modules->decryptSecret($telegram['bot_token']));
        $this->assertSame('smtp-secret', $modules->decryptSecret($modules->settings($imported, 'messaging')['email']['password']));
        $this->assertSame(
            MessageTemplate::query()->where('church_id', $imported->id)->where('key', 'telegram_confirmation')->value('id'),
            $telegram['confirmation_template_id'],
        );
    }

    public function test_imported_people_never_become_platform_admins(): void
    {
        $this->admin->forceFill(['is_platform_admin' => true])->save();

        app(ChurchExporter::class)->export($this->church, $this->zipPath, includeSecrets: false);
        $this->freeEmailsAsIfImportingOnAnotherServer();
        $imported = app(ChurchImporter::class)->import($this->zipPath)['church'];

        $this->assertFalse(User::query()->where('church_id', $imported->id)->firstOrFail()->is_platform_admin);
    }

    public function test_import_refuses_an_export_from_a_newer_version(): void
    {
        app(ChurchExporter::class)->export($this->church, $this->zipPath, includeSecrets: false);
        $this->rewriteManifest(['latest_migration' => '2099_01_01_000000_from_the_future']);
        $churches = Church::query()->count();

        try {
            app(ChurchImporter::class)->import($this->zipPath);
            $this->fail('The import should have been refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('This export was made by a newer version of WorshipPlanning. Update this server first.', $exception->getMessage());
        }

        $this->assertSame($churches, Church::query()->count());
    }

    public function test_import_refuses_people_whose_email_already_has_an_account(): void
    {
        app(ChurchExporter::class)->export($this->church, $this->zipPath, includeSecrets: false);
        $churches = Church::query()->count();

        try {
            app(ChurchImporter::class)->import($this->zipPath);
            $this->fail('An email that already has an account on this server must stop the import.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('anna@grace.test', $exception->getMessage());
        }

        $this->assertSame($churches, Church::query()->count());
    }

    public function test_imported_rows_always_belong_to_the_new_church(): void
    {
        app(ChurchExporter::class)->export($this->church, $this->zipPath, includeSecrets: false);
        $this->editZipTable('roles', fn (array $rows): array => [...$rows, ['id' => 99999, 'church_id' => null, 'name' => 'Everywhere Helper', 'guard_name' => 'web']]);
        $this->editZipTable('model_has_roles', fn (array $rows): array => [...$rows, [...$rows[0], 'model_type' => 'App\\Models\\Church']]);

        $this->freeEmailsAsIfImportingOnAnotherServer();
        $imported = app(ChurchImporter::class)->import($this->zipPath)['church'];

        $this->assertSame(0, DB::table('roles')->whereNull('church_id')->count(), 'no role may be shared by every church');
        $this->assertSame($imported->id, (int) DB::table('roles')->where('name', 'Everywhere Helper')->value('church_id'));
        $this->assertSame(0, DB::table('model_has_roles')->where('model_type', '!=', User::class)->count());
    }

    public function test_import_ignores_file_paths_that_point_outside_the_church_folder(): void
    {
        DB::table('users')->where('id', $this->admin->id)->update(['avatar_path' => '../../.env']);
        app(ChurchExporter::class)->export($this->church, $this->zipPath, includeSecrets: false);

        $zip = new ZipArchive;
        $zip->open($this->zipPath);
        $zip->addFromString('files/../../.env', 'APP_KEY=stolen');
        $zip->close();

        $this->freeEmailsAsIfImportingOnAnotherServer();
        $imported = app(ChurchImporter::class)->import($this->zipPath)['church'];

        $this->assertNull(User::query()->where('church_id', $imported->id)->value('avatar_path'));
    }

    public function test_commands_refuse_to_run_in_demo_mode(): void
    {
        config(['demo.enabled' => true]);

        $this->artisan('church:export', ['church' => $this->church->id, 'path' => $this->zipPath])->assertFailed();
        $this->artisan('church:import', ['path' => $this->zipPath, '--force' => true])->assertFailed();

        $this->assertFileDoesNotExist($this->zipPath);
    }

    public function test_commands_export_and_import_a_church(): void
    {
        $this->artisan('church:export', ['church' => 'grace-church', 'path' => $this->zipPath])->assertSuccessful();
        $this->freeEmailsAsIfImportingOnAnotherServer();
        $this->artisan('church:import', ['path' => $this->zipPath, '--force' => true])->assertSuccessful();

        $this->assertSame(2, Church::query()->where('slug', 'like', 'grace-church%')->count());
    }

    public function test_church_admin_downloads_the_export_once_after_confirming(): void
    {
        $this->actAsChurchUser($this->admin);

        $url = Livewire::test(ExportChurchData::class)
            ->fillForm(['understood' => true])
            ->call('download')
            ->assertHasNoFormErrors()
            ->effects['redirect'];

        $response = $this->get($url)->assertOk()->assertDownload(Str::slug($this->church->name).'-'.now()->format('Y-m-d').'.zip');
        ob_start();
        $response->baseResponse->sendContent();
        $this->assertStringStartsWith('PK', (string) ob_get_clean(), 'a zip archive');

        $this->get($url)->assertNotFound();
        $this->assertSame([], glob(ExportChurchData::exportDirectory().'/*.zip'), 'the file is deleted once downloaded');
    }

    public function test_an_admin_of_another_church_cannot_download_the_export(): void
    {
        $this->actAsChurchUser($this->admin);
        $url = Livewire::test(ExportChurchData::class)->fillForm(['understood' => true])->call('download')->effects['redirect'];

        $otherChurch = Church::factory()->create();
        RolesAndPermissionsSeeder::seedForChurch($otherChurch);
        $otherAdmin = User::factory()->create(['church_id' => $otherChurch->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($otherAdmin, 'Church Admin');

        $this->actingAs($otherAdmin)->get($url)->assertNotFound();

        array_map(unlink(...), glob(ExportChurchData::exportDirectory().'/*.zip'));
    }

    public function test_export_needs_the_personal_data_confirmation(): void
    {
        $this->actAsChurchUser($this->admin);

        Livewire::test(ExportChurchData::class)
            ->fillForm(['understood' => false])
            ->call('download')
            ->assertHasFormErrors(['understood' => 'accepted'])
            ->assertNoRedirect();
    }

    public function test_members_cannot_open_the_export_page(): void
    {
        $member = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($member, 'Member');
        $this->actAsChurchUser($member);

        $this->get(ExportChurchData::getUrl())->assertForbidden();
    }

    public function test_the_export_page_is_locked_in_demo_mode(): void
    {
        config(['demo.enabled' => true]);
        $this->actAsChurchUser($this->admin);

        $this->get(ExportChurchData::getUrl())->assertForbidden();
    }

    public function test_platform_admin_imports_a_church_from_the_churches_page(): void
    {
        app(ChurchExporter::class)->export($this->church, $this->zipPath, includeSecrets: false);
        $upload = UploadedFile::fake()->createWithContent('grace.zip', (string) file_get_contents($this->zipPath));
        $this->freeEmailsAsIfImportingOnAnotherServer();
        $this->actingAs(User::factory()->platformAdmin()->create());

        Livewire::test(ManageChurches::class)
            ->callAction('import', data: ['export' => $upload, 'understood' => true])
            ->assertHasNoActionErrors();

        $imported = Church::query()->where('slug', 'grace-church-2')->firstOrFail();
        $this->assertSame(1, User::query()->where('church_id', $imported->id)->count());
    }

    public function test_the_import_action_is_hidden_in_demo_mode(): void
    {
        config(['demo.enabled' => true]);
        $this->actingAs(User::factory()->platformAdmin()->create());

        Livewire::test(ManageChurches::class)->assertActionHidden('import');
    }

    /**
     * Emails are unique on a server. A real move imports on another server, so give the source
     * church's people other emails before importing their export into the same test database.
     */
    private function freeEmailsAsIfImportingOnAnotherServer(): void
    {
        User::query()->where('church_id', $this->church->id)->each(
            fn (User $user) => $user->forceFill(['email' => 'source-'.$user->email])->saveQuietly()
        );
    }

    private function actAsChurchUser(User $user): void
    {
        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
        $this->actingAs($user);
    }

    private function zipContents(): string
    {
        $zip = new ZipArchive;
        $zip->open($this->zipPath);
        $contents = '';

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $contents .= $zip->getFromIndex($index);
        }

        $zip->close();

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function rewriteManifest(array $changes): void
    {
        $zip = new ZipArchive;
        $zip->open($this->zipPath);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->addFromString('manifest.json', json_encode([...$manifest, ...$changes]));
        $zip->close();
    }

    /**
     * @param  callable(array<int, array<string, mixed>>): array<int, array<string, mixed>>  $change
     */
    private function editZipTable(string $table, callable $change): void
    {
        $zip = new ZipArchive;
        $zip->open($this->zipPath);
        $rows = json_decode((string) $zip->getFromName("data/{$table}.json"), true) ?: [];
        $zip->addFromString("data/{$table}.json", json_encode($change($rows)));
        $zip->close();
    }
}
