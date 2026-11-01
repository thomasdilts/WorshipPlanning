<?php

namespace App\Filament\Pages;

use App\Filament\Tables\OutboundMessagesTable;
use App\Models\OutboundMessage;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Messaging\Support\MessagingAvailability;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every message sent in the church, of every kind — for people allowed to view all messages.
 */
class MessageHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static ?string $slug = 'message-history';

    protected string $view = 'filament-panels::pages.page';

    public static function getNavigationLabel(): string
    {
        return __('Message history');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Message history');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Every message sent in this church, to whom, and whether it arrived.');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->church === null) {
            return false;
        }

        if (! app(MessagingAvailability::class)->anyEnabled($user->church)) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('messaging.view');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return OutboundMessagesTable::configure(
            $table
                ->query(OutboundMessage::query()->where('church_id', auth()->user()?->church_id ?? 0))
                ->emptyStateHeading(__('No messages yet')),
            ['people_filters' => true],
        );
    }
}
