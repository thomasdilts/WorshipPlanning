<?php

namespace Tests\Feature\Auth;

use App\Mail\ChurchAccessRequestMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'special_requests' => 'We worship in Swedish and English.',
        ])->assertRedirect(route('partner.create'))
            ->assertSessionHas('status');

        Mail::assertSent(ChurchAccessRequestMail::class, function (ChurchAccessRequestMail $mail): bool {
            return $mail->hasTo('ops@example.test')
                && $mail->payload['church_name'] === 'Grace Fellowship'
                && $mail->payload['expected_users'] === '11-30';
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
            ->assertSessionHasErrors(['expected_users']);

        Mail::assertNothingSent();
    }
}
