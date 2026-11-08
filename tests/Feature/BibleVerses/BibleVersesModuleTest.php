<?php

namespace Tests\Feature\BibleVerses;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\FieldUsage;
use App\Filament\Pages\BibleSettings;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\TasksRelationManager;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\EventCopier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\BibleVerses\Services\ZamarBibleClient;
use Modules\BibleVerses\Support\PassageRef;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BibleVersesModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($this->church, 'bible_verses');

        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');

        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
    }

    public function test_admin_can_open_bible_settings_when_module_enabled(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/bible-settings')
            ->assertOk();
    }

    public function test_bible_settings_forbidden_when_module_disabled(): void
    {
        app(ChurchModuleManager::class)->disable($this->church, 'bible_verses');
        $this->admin->unsetRelation('church');

        $this->actingAs($this->admin)
            ->get('/admin/bible-settings')
            ->assertForbidden();
    }

    public function test_admin_can_save_bible_api_settings(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(BibleSettings::class)
            ->fillForm([
                'base_url' => 'http://bible.test',
                'api_key' => 'secret-key',
                'default_bible' => 'kjv',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(ChurchModuleManager::class)->settings($this->church->fresh(), 'bible_verses');

        $this->assertSame('http://bible.test', $settings['base_url']);
        $this->assertSame('kjv', $settings['default_bible']);
        $this->assertNotSame('secret-key', $settings['api_key']);
        $this->assertSame('secret-key', app(ChurchModuleManager::class)->decryptSecret($settings['api_key']));
    }

    public function test_zamar_client_lists_bibles_via_api(): void
    {
        $this->configureBibleApi();

        Http::preventStrayRequests();
        Http::fake([
            'http://bible.test/api/bibles' => Http::response([
                'data' => [
                    ['id' => 'kjv', 'name' => 'King James Version'],
                    ['id' => 'svd', 'name' => 'Smith Van Dyck'],
                ],
            ]),
        ]);

        $bibles = app(ZamarBibleClient::class)->bibles($this->church);

        $this->assertSame([
            ['code' => 'kjv', 'name' => 'King James Version'],
            ['code' => 'svd', 'name' => 'Smith Van Dyck'],
        ], $bibles);

        Http::assertSentCount(1);
    }

    public function test_empty_church_settings_fall_back_to_env_and_strip_api_suffix(): void
    {
        config([
            'services.zamar_bible.base_url' => 'https://bible.example.com/api',
            'services.zamar_bible.api_key' => 'env-key',
            'services.zamar_bible.default_bible' => 'kjv',
        ]);

        app(ChurchModuleManager::class)->updateSettings($this->church, 'bible_verses', [
            'base_url' => '',
            'api_key' => '',
            'default_bible' => 'kjv',
        ]);

        $settings = app(ZamarBibleClient::class)->settings($this->church);

        $this->assertSame('https://bible.example.com', $settings['base_url']);
        $this->assertSame('env-key', $settings['api_key']);
    }

    public function test_zamar_client_fetches_passage_text(): void
    {
        $this->configureBibleApi();

        Http::preventStrayRequests();
        Http::fake([
            'http://bible.test/api/bibles/kjv/passages/*' => Http::response([
                'data' => [
                    'reference' => 'John 3:16',
                    'content' => 'For God so loved the world…',
                    'verses' => [
                        ['verse' => 16, 'text' => 'For God so loved the world…'],
                    ],
                    'copyright' => 'Public domain',
                ],
            ]),
        ]);

        $passage = app(ZamarBibleClient::class)->passage('kjv', 'JOHN.3.16', $this->church);

        $this->assertSame('John 3:16', $passage['reference']);
        $this->assertStringContainsString('loved', $passage['content']);
        $this->assertSame('Public domain', $passage['copyright']);
    }

    public function test_passage_ref_builds_and_parses_osis(): void
    {
        $this->assertSame('JOHN.3.16', PassageRef::build('JOHN', 3, 16));
        $this->assertSame('PS.23.1-6', PassageRef::build('PS', 23, 1, 6));
        $this->assertSame('Psalms 23:1–6', PassageRef::label('PS', 23, 1, 6));

        $parsed = PassageRef::parse('JOHN.3.16');
        $this->assertSame([
            'book' => 'JOHN',
            'chapter' => 3,
            'verse_start' => 16,
            'verse_end' => null,
        ], $parsed);
    }

    public function test_passage_preview_returns_verse_text(): void
    {
        $this->configureBibleApi();

        Http::preventStrayRequests();
        Http::fake([
            'http://bible.test/api/bibles/kjv/passages/*' => Http::response([
                'data' => [
                    'reference' => 'John 3:16',
                    'content' => 'For God so loved the world…',
                    'verses' => [],
                    'copyright' => 'Public domain',
                ],
            ]),
        ]);

        $preview = app(ZamarBibleClient::class)->passagePreview('kjv', 'JOHN.3.16', $this->church);

        $this->assertStringContainsString('For God so loved the world', $preview);
        $this->assertStringContainsString('Public domain', $preview);
    }

    public function test_event_task_stores_collapsed_bible_passages(): void
    {
        $this->configureBibleApi();

        Http::preventStrayRequests();
        Http::fake([
            'http://bible.test/api/bibles' => Http::response([
                'data' => [
                    ['id' => 'kjv', 'name' => 'King James Version'],
                ],
            ]),
        ]);

        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'bible_usage' => FieldUsage::Required,
            'person_usage' => FieldUsage::NotUsed,
            'team_usage' => FieldUsage::NotUsed,
        ]);

        $event = Event::factory()->create(['church_id' => $this->church->id]);

        $this->actingAs($this->admin);

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('create', data: [
                'task_template_id' => $template->id,
                'name' => 'Reading',
                'bible_passages' => [
                    [
                        'bible' => 'kjv',
                        'book' => 'JOHN',
                        'chapter' => 3,
                        'verse_start' => 16,
                        'verse_end' => null,
                    ],
                ],
                'sort_order' => 1,
                'duration' => 5,
            ])
            ->assertHasNoTableActionErrors();

        $task = EventTask::query()->where('event_id', $event->id)->first();
        $this->assertNotNull($task);
        $this->assertSame([
            [
                'bible' => 'kjv',
                'ref' => 'JOHN.3.16',
                'label' => 'John 3:16',
            ],
        ], $task->bible_passages);
    }

    public function test_event_copy_copies_bible_passages(): void
    {
        $template = TaskTemplate::factory()->create([
            'church_id' => $this->church->id,
            'bible_usage' => FieldUsage::Optional,
        ]);
        $source = Event::factory()->create([
            'church_id' => $this->church->id,
            'starts_at' => now()->addWeek(),
        ]);
        EventTask::factory()->create([
            'event_id' => $source->id,
            'task_template_id' => $template->id,
            'bible_passages' => [
                ['bible' => 'kjv', 'ref' => 'PS.23.1-6', 'label' => 'Psalms 23:1–6'],
            ],
        ]);

        $copy = app(EventCopier::class)->copy($source, [
            'starts_at' => now()->addWeeks(2),
        ])->first();

        $copiedTask = $copy->tasks->first();
        $this->assertNotNull($copiedTask);
        $this->assertSame('PS.23.1-6', $copiedTask->bible_passages[0]['ref']);
        $this->assertSame('kjv', $copiedTask->bible_passages[0]['bible']);
    }

    protected function configureBibleApi(): void
    {
        app(ChurchModuleManager::class)->updateSettings($this->church, 'bible_verses', [
            'base_url' => 'http://bible.test',
            'api_key' => app(ChurchModuleManager::class)->encryptSecret('test-key'),
            'default_bible' => 'kjv',
        ]);
    }
}
