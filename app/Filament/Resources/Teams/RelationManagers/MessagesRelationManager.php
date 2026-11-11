<?php

namespace App\Filament\Resources\Teams\RelationManagers;

use App\Enums\MessagePreference;
use App\Filament\Resources\Teams\Actions\MessageTeamAction;
use App\Models\OutboundMessage;
use App\Models\Team;
use App\Models\User;
use App\Support\UserAvatar;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Messaging\Support\MessagingAvailability;
use Spatie\Permission\PermissionRegistrar;

/**
 * Messages members sent to each other through this team — one row per recipient, from the send log.
 */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'teamMessages';

    /** @var array<int, User> */
    protected array $senders = [];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Messages');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        if (! $ownerRecord instanceof Team || ! $user instanceof User || $user->church === null) {
            return false;
        }

        if (! app(MessagingAvailability::class)->anyEnabled($user->church) || ! $user->can('view', $ownerRecord)) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $ownerRecord->isMember($user) || $user->can('teams.manage') || $user->can('messaging.view');
    }

    protected function sender(OutboundMessage $message): ?User
    {
        $senderId = (int) ($message->meta['from_user_id'] ?? 0);

        if ($senderId === 0) {
            return null;
        }

        return $this->senders[$senderId] ??= User::query()->find($senderId);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'event']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Sent'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('from')
                    ->disabledClick()
                    ->label(__('From'))
                    ->state(fn (OutboundMessage $record): string => ($sender = $this->sender($record))
                        ? UserAvatar::html($sender)->toHtml()
                        : '—')
                    ->html(),
                TextColumn::make('user.display_name')
                    ->disabledClick()
                    ->label(__('To'))
                    ->html()
                    ->formatStateUsing(fn ($state, OutboundMessage $record): string => $record->user
                        ? UserAvatar::html($record->user)->toHtml()
                        : '—')
                    ->placeholder('—'),
                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->placeholder('—')
                    ->limit(40),
                TextColumn::make('channel')
                    ->label(__('Type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => MessagePreference::tryFrom($state)?->label() ?? $state),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sent' => __('Sent'),
                        'failed' => __('Failed'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => $state === 'sent' ? 'success' : 'danger')
                    ->tooltip(fn (OutboundMessage $record): ?string => $record->error),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->label(__('Type'))
                    ->options(MessagePreference::options()),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(['sent' => __('Sent'), 'failed' => __('Failed')]),
            ])
            ->headerActions([
                MessageTeamAction::make($this->getOwnerRecord()),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(__('Preview'))
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (OutboundMessage $record): string => __('Message to :name', [
                        'name' => $record->user?->displayName() ?? '—',
                    ]))
                    ->modalContent(fn (OutboundMessage $record) => view('filament.messaging.sent-message-preview', [
                        'message' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalWidth('3xl'),
            ])
            ->emptyStateHeading(__('No messages yet'))
            ->emptyStateDescription(__('Messages that team members send to each other appear here.'))
            ->emptyStateIcon(Heroicon::OutlinedEnvelope);
    }
}
