<?php

namespace Tests\Feature\Auth;

use App\Mail\ChurchAccessRequestMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RootRedirectAndAccessRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_root_redirects_to_admin(): void
    {
        $this->get('/')
            ->assertRedirect('/admin');
    }

    public function test_login_page_includes_partner_cta(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee(__('Plan the service. Staff the order.'), false)
            ->assertSee(route('partner.create'), false);
    }

    public function test_partner_form_sends_mail(): void
    {
        Mail::fake();

        config([
            'worshipplanning.church_access_request_to' => 'ops@example.test',
            'mail.from.address' => 'noreply@example.test',
            'mail.from.name' => 'WorshipPlanning',
        ]);

        $this->get(route('partner.create'))->assertOk();

        $this->post(route('partner.store'), [
            'church_name' => 'Grace Fellowship',
            'address' => '12 Oak Street, Springfield',
            'country' => 'SE',
            'phone' => '+46 70 123 45 67',
            'email' => 'pastor@grace.test',
            'expected_users' => '11-30',
            'required_languages' => 'Swedish, English',
            'special_requests' => 'We worship in Swedish and English.',
        ])->assertRedirect(route('partner.create'))
            ->assertSessionHas('status');

        Mail::assertSent(ChurchAccessRequestMail::class, function (ChurchAccessRequestMail $mail): bool {
            return $mail->hasTo('ops@example.test')
                && $mail->payload['church_name'] === 'Grace Fellowship'
                && $mail->payload['expected_users'] === '11-30'
                && $mail->payload['required_languages'] === 'Swedish, English';
        });
    }

    public function test_partner_form_requires_expected_users(): void
    {
        Mail::fake();

        config(['worshipplanning.church_access_request_to' => 'ops@example.test']);

        $this->from(route('partner.create'))
            ->post(route('partner.store'), [
                'church_name' => 'Grace Fellowship',
                'address' => '12 Oak Street',
                'country' => 'SE',
                'phone' => '+46 70 123 45 67',
                'email' => 'pastor@grace.test',
                'special_requests' => null,
            ])
            ->assertSessionHasErrors(['expected_users', 'required_languages']);

        Mail::assertNothingSent();
    }

    public function test_partner_form_shows_turnstile_when_configured(): void
    {
        $this->enableTurnstile();

        $this->get(route('partner.create'))
            ->assertOk()
            ->assertSee('data-sitekey="site-key"', false);
    }

    public function test_partner_form_rejects_a_token_cloudflare_refuses(): void
    {
        Mail::fake();
        $this->enableTurnstile();
        Http::fake(['https://challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this->from(route('partner.create'))
            ->post(route('partner.store'), $this->validRequest() + ['cf-turnstile-response' => 'bad-token'])
            ->assertSessionHasErrors(['turnstile']);

        Mail::assertNothingSent();
    }

    public function test_partner_form_sends_mail_when_cloudflare_accepts_the_token(): void
    {
        Mail::fake();
        $this->enableTurnstile();
        Http::fake(['https://challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->post(route('partner.store'), $this->validRequest() + ['cf-turnstile-response' => 'good-token'])
            ->assertRedirect(route('partner.create'))
            ->assertSessionHasNoErrors();

        Mail::assertSent(ChurchAccessRequestMail::class);
    }

    private function enableTurnstile(): void
    {
        config([
            'services.turnstile.site_key' => 'site-key',
            'services.turnstile.secret_key' => 'secret-key',
            'worshipplanning.church_access_request_to' => 'ops@example.test',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function validRequest(): array
    {
        return [
            'church_name' => 'Grace Fellowship',
            'address' => '12 Oak Street',
            'country' => 'SE',
            'phone' => '+46 70 123 45 67',
            'email' => 'pastor@grace.test',
            'expected_users' => '11-30',
            'required_languages' => 'Swedish',
        ];
    }
}
