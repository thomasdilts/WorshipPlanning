<?php

namespace Tests\Feature\Messaging;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Filament\Pages\MessageHistory;
use App\Filament\Pages\MyMessages;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventMessage;
use App\Models\OutboundMessage;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MessageHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected function setUp(): void
    {
        parent::setUp();

        $this->church = Church::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        app(ChurchModuleManager::class)->enable($this->church, 'messaging');
        app(ChurchModuleManager::class)->enable($this->church, 'teams');
        app(CurrentChurch::class)->set($this->church);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->church->id);
    }

    public function test_my_messages_shows_only_messages_sent_to_me(): void
    {
        $member = $this->person('Member');
        $mine = OutboundMessage::factory()->create(['church_id' => $this->church->id, 'user_id' => $member->id]);
        $someoneElses = OutboundMessage::factory()->create(['church_id' => $this->church->id, 'user_id' => $this->person('Member')->id]);
        $this->actingAs($member);

        Livewire::test(MyMessages::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$someoneElses]);
    }

    public function test_sent_tab_lists_team_and_event_messages_i_wrote(): void
    {
        $author = $this->person('Event Planner');
        $other = $this->person('Event Planner');
        $team = Team::factory()->create(['church_id' => $this->church->id]);
        $team->syncRoster([$author->id, $other->id], [$author->id]);

        $myTeamMessage = OutboundMessage::factory()->teamMessage($team, $author)->create(['user_id' => $other->id]);
        $myEventMessage = OutboundMessage::factory()->eventMessage($this->eventMessageBy($author))->create(['user_id' => $other->id]);
        $othersTeamMessage = OutboundMessage::factory()->teamMessage($team, $other)->create(['user_id' => $author->id]);
        $othersEventMessage = OutboundMessage::factory()->eventMessage($this->eventMessageBy($other))->create(['user_id' => $author->id]);
        $this->actingAs($author);

        Livewire::test(MyMessages::class)
            ->set('activeTab', 'sent')
            ->assertCanSeeTableRecords([$myTeamMessage, $myEventMessage])
            ->assertCanNotSeeTableRecords([$othersTeamMessage, $othersEventMessage]);
    }

    public function test_sent_tab_is_hidden_for_people_who_cannot_send(): void
    {
        $this->actingAs($this->person('Member'));

        $this->assertSame(['received'], array_keys(Livewire::test(MyMessages::class)->instance()->getCachedTabs()));
    }

    public function test_message_history_is_only_for_people_who_view_all_messages(): void
    {
        $this->actingAs($this->person('Member'));
        $this->get(MessageHistory::getUrl())->assertForbidden();

        $this->actingAs($this->person('Event Planner'));
        $this->get(MessageHistory::getUrl())->assertForbidden();

        $this->actingAs($this->person('Church Admin'));
        $this->get(MessageHistory::getUrl())->assertOk();
    }

    public function test_message_history_shows_every_message_and_filters_by_kind_and_sender(): void
    {
        $author = $this->person('Event Planner');
        $recipient = $this->person('Member');
        $team = Team::factory()->create(['church_id' => $this->church->id]);
        $team->syncRoster([$author->id, $recipient->id], [$author->id]);

        $system = OutboundMessage::factory()->create(['church_id' => $this->church->id, 'user_id' => $recipient->id]);
        $teamMessage = OutboundMessage::factory()->teamMessage($team, $author)->create(['user_id' => $recipient->id]);
        $eventMessage = OutboundMessage::factory()->eventMessage($this->eventMessageBy($author))->create(['user_id' => $recipient->id]);
        $otherChurch = OutboundMessage::factory()->create();
        $this->actingAs($this->person('Church Admin'));

        Livewire::test(MessageHistory::class)
            ->assertCanSeeTableRecords([$system, $teamMessage, $eventMessage])
            ->assertCanNotSeeTableRecords([$otherChurch])
            ->filterTable('kind', OutboundMessage::KIND_TEAM)
            ->assertCanSeeTableRecords([$teamMessage])
            ->assertCanNotSeeTableRecords([$system, $eventMessage])
            ->filterTable('kind', OutboundMessage::KIND_SYSTEM)
            ->assertCanSeeTableRecords([$system])
            ->assertCanNotSeeTableRecords([$teamMessage, $eventMessage])
            ->removeTableFilter('kind')
            ->filterTable('sender', $author->id)
            ->assertCanSeeTableRecords([$teamMessage, $eventMessage])
            ->assertCanNotSeeTableRecords([$system]);
    }

    public function test_only_the_author_or_an_admin_can_delete_an_event_message(): void
    {
        $author = $this->person('Event Planner');
        $message = $this->eventMessageBy($author);

        $this->assertTrue($author->can('delete', $message));
        $this->assertFalse($this->person('Event Planner')->can('delete', $message));
        $this->assertTrue($this->person('Church Admin')->can('delete', $message));
    }

    public function test_view_all_messages_is_removed_from_default_non_admin_roles(): void
    {
        $viewAll = Permission::findOrCreate('messaging.view', 'web');

        foreach (['Member', 'Event Planner', 'Church Admin'] as $name) {
            Role::findByName($name, 'web')->givePermissionTo($viewAll);
        }

        (require base_path('database/migrations/2026_11_01_054114_limit_view_all_messages_to_admins.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(Role::findByName('Member', 'web')->hasPermissionTo('messaging.view'));
        $this->assertFalse(Role::findByName('Event Planner', 'web')->hasPermissionTo('messaging.view'));
        $this->assertTrue(Role::findByName('Church Admin', 'web')->hasPermissionTo('messaging.view'));
    }

    protected function person(string $role): User
    {
        $user = User::factory()->create(['church_id' => $this->church->id]);
        RolesAndPermissionsSeeder::assignDefaultRoles($user, $role);

        return $user;
    }

    protected function eventMessageBy(User $author): EventMessage
    {
        return EventMessage::query()->create([
            'church_id' => $this->church->id,
            'event_id' => Event::factory()->create(['church_id' => $this->church->id])->id,
            'created_by_user_id' => $author->id,
            'subject' => 'Rehearsal',
            'body' => 'See you there',
            'status' => EventMessage::STATUS_SENT,
        ]);
    }
}
