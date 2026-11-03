<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\Login;
use App\Models\Church;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class TurnstileLoginTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create([
            'church_id' => $church->id,
            'email' => 'anna@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($this->user, 'Member');
    }

    public function test_login_without_turnstile_keys_does_not_call_cloudflare(): void
    {
        Http::fake();

        $this->signIn(token: null);

        $this->assertAuthenticatedAs($this->user);
        Http::assertNothingSent();
    }

    public function test_rejects_login_when_cloudflare_says_the_token_is_invalid(): void
    {
        $this->enableTurnstile();
        Http::fake([self::VERIFY_URL => Http::response(['success' => false])]);

        $this->signIn(token: 'bad-token')->assertHasErrors(['data.email']);

        $this->assertGuest();
    }

    public function test_rejects_login_without_a_token_and_does_not_call_cloudflare(): void
    {
        $this->enableTurnstile();
        Http::fake();

        $this->signIn(token: null)->assertHasErrors(['data.email']);

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_rejects_login_when_cloudflare_is_unreachable(): void
    {
        $this->enableTurnstile();
        Http::fake([self::VERIFY_URL => Http::response('', 503)]);

        $this->signIn(token: 'good-token')->assertHasErrors(['data.email']);

        $this->assertGuest();
    }

    public function test_signs_in_when_cloudflare_accepts_the_token_and_resets_the_widget(): void
    {
        $this->enableTurnstile();
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);

        $this->signIn(token: 'good-token')
            ->assertHasNoErrors()
            ->assertDispatched('turnstile-reset');

        $this->assertAuthenticatedAs($this->user);
        Http::assertSent(fn (Request $request): bool => $request['secret'] === 'test-secret' && $request['response'] === 'good-token');
    }

    public function test_login_page_shows_the_widget_only_when_turnstile_is_on(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSee('challenges.cloudflare.com/turnstile', false);

        $this->enableTurnstile();

        $this->get('/admin/login')->assertOk()
            ->assertSee('challenges.cloudflare.com/turnstile', false)
            ->assertSee('test-site-key', false);
    }

    private function enableTurnstile(): void
    {
        config(['services.turnstile.site_key' => 'test-site-key', 'services.turnstile.secret_key' => 'test-secret']);
    }

    private function signIn(?string $token): mixed
    {
        return Livewire::test(Login::class)
            ->set('turnstileToken', $token)
            ->fillForm(['email' => 'anna@example.test', 'password' => 'secret-password'])
            ->call('authenticate');
    }
}
