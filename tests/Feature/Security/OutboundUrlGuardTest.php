<?php

namespace Tests\Feature\Security;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\MessagePreference;
use App\Filament\Pages\BibleSettings;
use App\Filament\Pages\MessagingSettings;
use App\Filament\Pages\NtfyMessagingSettings;
use App\Models\Church;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Support\OutboundUrlGuard;
use Database\Seeders\LanguagesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\BibleVerses\Services\ZamarBibleClient;
use Modules\Messaging\Data\OutboundMessageData;
use Modules\Messaging\Services\MessageSender;
use Modules\Messaging\Support\MessagingAvailability;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Addresses church admins type in must not let this server reach its own private network, and the
 * platform's Bible API key must only go to the platform's Bible API.
 */
class OutboundUrlGuardTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // internal.example resolves into the private network; every other made-up name is public.
        $this->app->instance(OutboundUrlGuard::class, new class extends OutboundUrlGuard
        {
            public function resolve(string $host): array
            {
                $host = trim($host, '[]');

                return match (true) {
                    filter_var($host, FILTER_VALIDATE_IP) !== false => [$host],
                    $host === 'internal.example' => ['10.0.0.5'],
                    default => ['93.184.215.14'],
                };
            }
        });

        $this->church = Church::factory()->create();
        $this->seed([RolesAndPermissionsSeeder::class, LanguagesSeeder::class]);
        $this->admin = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->admin, 'Church Admin');
        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
        $this->actingAs($this->admin);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function urls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1:8080', false],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data', false],
            'private network' => ['http://192.168.1.10', false],
            'carrier-grade NAT' => ['http://100.64.0.1', false],
            'IPv6 loopback' => ['http://[::1]', false],
            'IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]', false],
            'name pointing into the private network' => ['https://internal.example', false],
            'credentials in the address' => ['https://user:pass@ntfy.example.org', false],
            'not a web address' => ['ftp://ntfy.example.org', false],
            'public host' => ['https://ntfy.example.org', true],
        ];
    }

    #[DataProvider('urls')]
    public function test_only_public_web_addresses_are_allowed(string $url, bool $allowed): void
    {
        $this->assertSame($allowed, app(OutboundUrlGuard::class)->problemWithUrl($url) === null);
    }

    public function test_local_development_switch_allows_private_addresses(): void
    {
        config(['services.outbound.allow_private_hosts' => true]);

        $this->assertNull(app(OutboundUrlGuard::class)->problemWithUrl('http://127.0.0.1:8000'));
    }

    public function test_bible_settings_refuse_a_private_address(): void
    {
        app(ChurchModuleManager::class)->enable($this->church, 'bible_verses');

        Livewire::test(BibleSettings::class)
            ->fillForm(['base_url' => 'http://169.254.169.254', 'api_key' => 'church-key', 'default_bible' => 'kjv'])
            ->call('save')
            ->assertHasFormErrors(['base_url']);
    }

    public function test_platform_bible_key_is_not_sent_to_a_church_chosen_host(): void
    {
        config(['services.zamar_bible.base_url' => 'https://bible.example.com', 'services.zamar_bible.api_key' => 'PLATFORM-KEY']);
        app(ChurchModuleManager::class)->enable($this->church, 'bible_verses', ['base_url' => 'https://collector.example.net', 'api_key' => '']);
        Http::fake(['https://collector.example.net/*' => Http::response(['data' => []])]);

        $this->expectException(RuntimeException::class);

        try {
            app(ZamarBibleClient::class)->bibles($this->church);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_platform_bible_key_is_used_with_the_platform_host(): void
    {
        config(['services.zamar_bible.base_url' => 'https://bible.example.com', 'services.zamar_bible.api_key' => 'PLATFORM-KEY']);
        app(ChurchModuleManager::class)->enable($this->church, 'bible_verses', ['base_url' => '', 'api_key' => '']);
        Http::fake(['https://bible.example.com/*' => Http::response(['data' => []])]);

        app(ZamarBibleClient::class)->bibles($this->church);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer PLATFORM-KEY'));
    }

    public function test_ntfy_settings_refuse_a_private_address(): void
    {
        app(ChurchModuleManager::class)->enable($this->church, MessagingAvailability::MODULE_NTFY);

        Livewire::test(NtfyMessagingSettings::class)
            ->fillForm(['server_url' => 'http://127.0.0.1:2379'])
            ->call('save')
            ->assertHasFormErrors(['server_url']);
    }

    public function test_ntfy_message_to_a_stored_private_address_is_not_sent(): void
    {
        $member = $this->ntfyMember(['server_url' => 'https://internal.example']);
        Http::fake(['https://internal.example/*' => Http::response(['id' => 'x'])]);

        $result = app(MessageSender::class)->sendToUser($member, new OutboundMessageData(subject: 'Hello', body: 'Hello'));

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_ntfy_failure_does_not_store_the_server_reply(): void
    {
        $member = $this->ntfyMember(['server_url' => 'https://ntfy.example.org']);
        Http::fake(['https://ntfy.example.org/*' => Http::response('INTERNAL-REPLY-TEXT', 500)]);

        $result = app(MessageSender::class)->sendToUser($member, new OutboundMessageData(subject: 'Hello', body: 'Hello'));

        $this->assertFalse($result->success);
        $this->assertStringNotContainsString('INTERNAL-REPLY-TEXT', (string) $result->error);
    }

    public function test_email_settings_refuse_a_private_host_and_a_non_smtp_port(): void
    {
        app(ChurchModuleManager::class)->enable($this->church, MessagingAvailability::MODULE_EMAIL);

        Livewire::test(MessagingSettings::class)
            ->fillForm(['host' => '127.0.0.1', 'port' => 6379, 'from_address' => 'church@example.org', 'from_name' => 'Church'])
            ->call('save')
            ->assertHasFormErrors(['host', 'port']);
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function ntfyMember(array $settings): User
    {
        $template = MessageTemplate::query()->create([
            'church_id' => $this->church->id, 'name' => 'Confirm', 'purpose' => 'other', 'text_body' => 'Hi', 'is_active' => true,
        ]);
        app(ChurchModuleManager::class)->enable($this->church, MessagingAvailability::MODULE_NTFY, [...$settings, 'confirmation_template_id' => $template->id]);

        return User::factory()->create([
            'church_id' => $this->church->id,
            'ntfy_topic' => 'guard-topic-abcdefghijklmnop',
            'message_preference' => MessagePreference::Ntfy,
        ]);
    }
}
