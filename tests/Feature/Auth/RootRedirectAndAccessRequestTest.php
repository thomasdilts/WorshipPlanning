<?php

namespace Tests\Feature\Auth;

use App\Mail\ChurchAccessRequestMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\TestWith;
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
            ->assertSee(__('Request access for your church'))
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
            'street_address' => '12 Oak Street',
            'city' => 'Springfield',
            'postal_code' => '01234',
            'admin_name' => 'Miriam Andersson',
            'admin_position' => 'Church administrator',
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
                && $mail->payload['required_languages'] === 'Swedish, English'
                && $mail->payload['street_address'] === '12 Oak Street'
                && $mail->payload['city'] === 'Springfield'
                && $mail->payload['postal_code'] === '01234'
                && $mail->payload['admin_name'] === 'Miriam Andersson'
                && $mail->payload['admin_position'] === 'Church administrator';
        });
    }

    public function test_partner_form_requires_expected_users(): void
    {
        Mail::fake();

        config(['worshipplanning.church_access_request_to' => 'ops@example.test']);

        $this->from(route('partner.create'))
            ->post(route('partner.store'), [
                'church_name' => 'Grace Fellowship',
                'street_address' => '12 Oak Street',
                'city' => 'Springfield',
                'postal_code' => '01234',
                'admin_name' => 'Miriam Andersson',
                'admin_position' => 'Church administrator',
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

    public function test_demo_sends_access_requests_with_the_real_mailer_not_the_log(): void
    {
        Mail::fake();
        config([
            'demo.enabled' => true,
            'demo.operator_mailer' => 'smtp',
            'mail.default' => 'log',
            'worshipplanning.church_access_request_to' => 'ops@example.test',
        ]);

        $this->post(route('partner.store'), $this->validRequest())
            ->assertRedirect(route('partner.create'));

        Mail::assertSent(ChurchAccessRequestMail::class, fn (ChurchAccessRequestMail $mail): bool => $mail->mailer === 'smtp'
            && $mail->hasTo('ops@example.test'));
    }

    #[TestWith(['street_address'])]
    #[TestWith(['city'])]
    #[TestWith(['postal_code'])]
    #[TestWith(['admin_name'])]
    #[TestWith(['admin_position'])]
    #[TestWith(['special_requests'])]
    public function test_partner_form_requires_each_new_required_detail(string $field): void
    {
        Mail::fake();
        $payload = $this->validRequest();
        unset($payload[$field]);

        $this->from(route('partner.create'))
            ->post(route('partner.store'), $payload)
            ->assertRedirect(route('partner.create'))
            ->assertSessionHasErrors([$field]);

        Mail::assertNothingSent();
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['https://grace.test'])]
    #[TestWith(['http://grace.test'])]
    public function test_partner_form_accepts_an_optional_website(?string $website): void
    {
        Mail::fake();
        config(['worshipplanning.church_access_request_to' => 'ops@example.test']);

        $this->post(route('partner.store'), $this->validRequest() + ['website' => $website])
            ->assertRedirect(route('partner.create'))
            ->assertSessionHasNoErrors();

        Mail::assertSent(ChurchAccessRequestMail::class, fn (ChurchAccessRequestMail $mail): bool => ($mail->payload['website'] ?? null) === ($website ?: null));
    }

    #[TestWith(['not a website'])]
    #[TestWith(['ftp://grace.test'])]
    #[TestWith(['javascript:alert(1)'])]
    public function test_partner_form_rejects_invalid_websites(string $website): void
    {
        Mail::fake();

        $this->from(route('partner.create'))
            ->post(route('partner.store'), $this->validRequest() + ['website' => $website])
            ->assertSessionHasErrors(['website']);

        Mail::assertNothingSent();
    }

    public function test_access_request_email_includes_the_new_details(): void
    {
        $mail = new ChurchAccessRequestMail($this->validRequest() + ['website' => 'https://grace.test']);

        $mail->assertSeeInText('12 Oak Street');
        $mail->assertSeeInText('Springfield');
        $mail->assertSeeInText('01234');
        $mail->assertSeeInText('Miriam Andersson');
        $mail->assertSeeInText('Church administrator');
        $mail->assertSeeInText('https://grace.test');
    }

    public function test_access_request_email_renders_without_a_website(): void
    {
        $mail = new ChurchAccessRequestMail($this->validRequest());

        $mail->assertSeeInText('Grace Fellowship');
        $mail->assertDontSeeInText('Church website');
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
            'street_address' => '12 Oak Street',
            'city' => 'Springfield',
            'postal_code' => '01234',
            'admin_name' => 'Miriam Andersson',
            'admin_position' => 'Church administrator',
            'country' => 'SE',
            'phone' => '+46 70 123 45 67',
            'email' => 'pastor@grace.test',
            'expected_users' => '11-30',
            'required_languages' => 'Swedish',
            'special_requests' => 'None',
        ];
    }
}
