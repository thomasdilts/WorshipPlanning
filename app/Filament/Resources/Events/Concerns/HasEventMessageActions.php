<?php

namespace App\Filament\Resources\Events\Concerns;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Modules\Messaging\Support\MessagingAvailability;
use Spatie\Permission\PermissionRegistrar;

trait HasEventMessageActions
{
    protected function eventMessagesAction(?Event $event = null): Action
    {
        $event ??= $this->getRecord();

        return Action::make('eventMessages')
            ->label(__('Service messages'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->button()
            ->color('gray')
            ->tooltip(__('Service messages'))
            ->url(fn (): string => EventResource::getUrl('messages', ['record' => $event]))
            ->badge(fn (): ?string => ($count = $event->messages()->count()) > 0 ? (string) $count : null)
            ->visible(fn (): bool => ! $event->is_template && $this->canSeeEventMessages());
    }

    protected function canSeeEventMessages(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->church_id === null || $user->church === null) {
            return false;
        }

        if (! app(MessagingAvailability::class)->anyEnabled($user->church)) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('messaging.view') || $user->can('messaging.send');
    }
}
