<?php

namespace Tests\Feature\Auth;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Church;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Services\UserInviter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_church_with_email_settings_can_invite_without_a_password(): void
    {
        Notification::fake();
        $church = Church::factory()->withEmailSettings()->create();
        $this->actingAsChurchAdmin($church);

        Livewire::test(CreateUser::class)
            ->fillForm($this->newPersonForm(['send_invite' => true]))
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'member@example.test')->first();
        $this->assertNotNull($user);
        $this->assertSame($church->id, $user->church_id);

        Notification::assertSentTo($user, UserInvitationNotification::class);
    }

    public function test_church_with_email_settings_can_set_a_password_and_send_invite(): void
    {
        Notification::fake();
        $this->actingAsChurchAdmin(Church::factory()->withEmailSettings()->create());

        Livewire::test(CreateUser::class)
            ->fillForm($this->newPersonForm(['send_invite' => true, 'password' => 'Chosen-Pass-2026']))
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'member@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('Chosen-Pass-2026', $user->password));
        Notification::assertSentTo($user, UserInvitationNotification::class);
    }

    public function test_church_with_email_settings_requires_a_password_when_invite_is_off(): void
    {
        Notification::fake();
        $this->actingAsChurchAdmin(Church::factory()->withEmailSettings()->create());

        Livewire::test(CreateUser::class)
            ->fillForm($this->newPersonForm(['send_invite' => false]))
            ->call('create')
            ->assertHasFormErrors(['password' => 'required']);

        $this->assertDatabaseMissing('users', ['email' => 'member@example.test']);
    }

    public function test_church_without_email_settings_requires_a_password_and_sends_no_invite(): void
    {
        Notification::fake();
        $this->actingAsChurchAdmin(Church::factory()->create());

        Livewire::test(CreateUser::class)
            ->assertFormFieldHidden('send_invite')
            ->fillForm($this->newPersonForm())
            ->call('create')
            ->assertHasFormErrors(['password' => 'required']);

        Livewire::test(CreateUser::class)
            ->fillForm($this->newPersonForm(['password' => 'Chosen-Pass-2026']))
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'member@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('Chosen-Pass-2026', $user->password));
        Notification::assertNothingSent();
    }

    public function test_inviter_refuses_when_church_has_no_email_settings(): void
    {
        Notification::fake();
        $user = User::factory()->create(['church_id' => Church::factory()->create()->id]);

        try {
            app(UserInviter::class)->send($user->loadMissing('church'));
            $this->fail('Expected the invite to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        Notification::assertNothingSent();
    }

    public function test_send_password_invite_action_only_shows_with_church_email_settings(): void
    {
        foreach ([false, true] as $hasEmailSettings) {
            $church = $hasEmailSettings
                ? Church::factory()->withEmailSettings()->create()
                : Church::factory()->create();
            $this->actingAsChurchAdmin($church);
            $person = User::factory()->create(['church_id' => $church->id]);

            $page = Livewire::test(EditUser::class, ['record' => $person->getRouteKey()]);

            $hasEmailSettings
                ? $page->assertActionVisible('resendInvite')
                : $page->assertActionHidden('resendInvite');
        }
    }

    public function test_invite_rate_limit_blocks_duplicate_send_within_a_minute(): void
    {
        Notification::fake();

        $church = Church::factory()->withEmailSettings()->create();
        $user = User::factory()->create(['church_id' => $church->id]);
        $inviter = app(UserInviter::class);

        $inviter->send($user->loadMissing('church'));
        Notification::assertSentTo($user, UserInvitationNotification::class);

        $this->expectException(ValidationException::class);
        $inviter->send($user);
    }

    public function test_invite_notification_uses_filament_reset_url(): void
    {
        $church = Church::factory()->withEmailSettings()->create(['name' => 'Test Church']);
        $user = User::factory()->create([
            'church_id' => $church->id,
            'email' => 'invitee@example.test',
        ]);

        $mail = (new UserInvitationNotification('test-token', 'Admin', 'Test Church'))
            ->toMail($user);

        $this->assertSame(__('Set your password for :church', ['church' => 'Test Church']), $mail->subject);
        $this->assertStringContainsString('password-reset', $mail->actionUrl);
        $this->assertStringContainsString(rawurlencode('invitee@example.test'), $mail->actionUrl);
    }

    protected function actingAsChurchAdmin(Church $church): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentChurch::class)->set($church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($church->id);

        $admin = User::factory()->create(['church_id' => $church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($admin, 'Church Admin');

        $this->actingAs($admin);

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function newPersonForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Member',
            'display_name' => 'Member',
            'email' => 'member@example.test',
            'roles' => ['Member'],
        ], $overrides);
    }
}
