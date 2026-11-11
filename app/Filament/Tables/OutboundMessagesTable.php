<?php

namespace App\Filament\Tables;

use App\Enums\MessagePreference;
use App\Filament\Resources\Events\EventResource;
use App\Models\OutboundMessage;
use App\Models\Team;
use App\Models\User;
use App\Support\UserAvatar;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The delivered-message log, shared by "My messages" and the admin "Message history".
 */
class OutboundMessagesTable
{
    /**
     * @param  array{show_sender?: bool, show_recipient?: bool, people_filters?: bool}  $options
     */
    public static function configure(Table $table, array $options = []): Table
    {
        $showSender = (bool) ($options['show_sender'] ?? true);
        $showRecipient = (bool) ($options['show_recipient'] ?? true);
        $peopleFilters = (bool) ($options['people_filters'] ?? false);

        /** @var array<int, User|null> $users */
        $users = [];
        /** @var array<int, string|null> $teamNames */
        $teamNames = [];

        $user = function (?int $id) use (&$users): ?User {
            if ($id === null) {
                return null;
            }

            return $users[$id] ??= User::query()->find($id);
        };

        $teamName = function (int $id) use (&$teamNames): ?string {
            return $teamNames[$id] ??= Team::query()->whereKey($id)->value('name');
        };

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'event', 'eventMessage']))
            ->defaultSort('created_at', 'desc')
            ->columns(array_values(array_filter([
                TextColumn::make('created_at')
                    ->label(__('Date'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('kind')
                    ->label(__('About'))
                    ->badge()
                    ->color(fn (OutboundMessage $record): string => match ($record->kind()) {
                        OutboundMessage::KIND_EVENT => 'primary',
                        OutboundMessage::KIND_TEAM => 'info',
                        default => 'gray',
                    })
                    ->state(fn (OutboundMessage $record): string => match ($record->kind()) {
                        OutboundMessage::KIND_EVENT => $record->event?->name ?? __('Service'),
                        OutboundMessage::KIND_TEAM => $teamName((int) $record->meta['team_id']) ?? __('Team'),
                        default => __('System'),
                    }),
                $showSender ? TextColumn::make('from')
                    ->disabledClick()
                    ->label(__('From'))
                    ->html()
                    ->state(fn (OutboundMessage $record): string => ($sender = $user($record->senderId()))
                        ? UserAvatar::html($sender)->toHtml()
                        : e(__('WorshipPlanning'))) : null,
                $showRecipient ? TextColumn::make('user.display_name')
                    ->disabledClick()
                    ->label(__('To'))
                    ->html()
                    ->formatStateUsing(fn ($state, OutboundMessage $record): string => $record->user
                        ? UserAvatar::html($record->user)->toHtml()
                        : '—')
                    ->placeholder('—') : null,
                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->placeholder('—')
                    ->searchable()
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
            ])))
            ->filters(array_values(array_filter([
                SelectFilter::make('kind')
                    ->label(__('About'))
                    ->options(OutboundMessage::kindOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->ofKind($data['value'])
                        : $query),
                SelectFilter::make('channel')
                    ->label(__('Type'))
                    ->options(MessagePreference::options()),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(['sent' => __('Sent'), 'failed' => __('Failed')]),
                $peopleFilters ? SelectFilter::make('sender')
                    ->label(__('From'))
                    ->options(fn (): array => self::churchPeople())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->sentBy(User::query()->findOrFail((int) $data['value']))
                        : $query) : null,
                $peopleFilters ? SelectFilter::make('user_id')
                    ->label(__('To'))
                    ->options(fn (): array => self::churchPeople())
                    ->searchable() : null,
            ])))
            ->recordActions([
                Action::make('preview')
                    ->label(__('Preview'))
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (OutboundMessage $record): string => $record->subject ?: __('Message'))
                    ->modalContent(fn (OutboundMessage $record) => view('filament.messaging.sent-message-preview', [
                        'message' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalWidth('3xl'),
                Action::make('openEvent')
                    ->label(__('Open service'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (OutboundMessage $record): bool => $record->event !== null
                        && (auth()->user()?->can('view', $record->event) ?? false))
                    ->url(fn (OutboundMessage $record): string => EventResource::getUrl('view', ['record' => $record->event_id])),
            ])
            ->emptyStateIcon(Heroicon::OutlinedEnvelope);
    }

    /**
     * @return array<int, string>
     */
    protected static function churchPeople(): array
    {
        return User::query()
            ->where('church_id', auth()->user()?->church_id)
            ->orderBy('display_name')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $person): array => [$person->id => $person->displayName()])
            ->all();
    }
}
