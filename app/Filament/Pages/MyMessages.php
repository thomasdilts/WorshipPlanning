<?php

namespace App\Filament\Pages;

use App\Filament\Tables\OutboundMessagesTable;
use App\Models\OutboundMessage;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Modules\Messaging\Support\MessagingAvailability;
use Spatie\Permission\PermissionRegistrar;

/**
 * A person's own messages: what they received, and what they sent when they can send.
 */
class MyMessages extends Page implements HasTable
{
    use HasTabs;
    use InteractsWithTable;

    #[Url]
    public ?string $activeTab = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $slug = 'my-messages';

    protected string $view = 'filament-panels::pages.page';

    public static function getNavigationLabel(): string
    {
        return __('My messages');
    }

    public function getTitle(): string|Htmlable
    {
        return __('My messages');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->church !== null
            && app(MessagingAvailability::class)->anyEnabled($user->church);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $tabs = [
            'received' => Tab::make(__('Received'))
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('user_id', $user->id)),
        ];

        if ($this->canSeeSent($user)) {
            $tabs['sent'] = Tab::make(__('Sent'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->sentBy($user));
        }

        return $tabs;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        $isSent = $this->activeTab === 'sent' && array_key_exists('sent', $this->getCachedTabs());

        return OutboundMessagesTable::configure(
            $table
                ->query(OutboundMessage::query()->where('church_id', auth()->user()?->church_id ?? 0))
                ->modifyQueryUsing($this->modifyQueryWithActiveTab(...))
                ->emptyStateHeading(__('No messages yet')),
            [
                'show_sender' => ! $isSent,
                'show_recipient' => $isSent,
            ],
        );
    }

    /**
     * Anyone who can send (event messaging, or messaging a team they are on) — or already has — sees their sent messages.
     */
    protected function canSeeSent(User $user): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('messaging.send')
            || $user->teams()->where('teams.church_id', $user->church_id)->exists()
            || OutboundMessage::query()->where('church_id', $user->church_id)->sentBy($user)->exists();
    }
}
