<?php

namespace App\Filament\Resources\Events\Pages;

use App\Enums\AssignmentState;
use App\Enums\MessagePreference;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Schemas\EventMessageForm;
use App\Models\Event;
use App\Models\EventMessage;
use App\Models\EventTask;
use App\Models\User;
use App\Services\AssignmentStateResolver;
use App\Support\UserAvatar;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Messaging\Services\EventMessageService;
use Modules\Messaging\Support\MessagingAvailability;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property-read Event $record
 */
class ManageEventMessages extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'messages';

    protected static ?string $relatedResource = null;

    /** @var array<int, AssignmentState>|null */
    protected ?array $taskStates = null;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    public function getTitle(): string|Htmlable
    {
        return __('Event messages');
    }

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();
        $record = $parameters['record'] ?? null;

        if ($record instanceof Event && $record->is_template) {
            return false;
        }

        if (! $user instanceof User || $user->church_id === null || $user->church === null) {
            return false;
        }

        if (! app(MessagingAvailability::class)->anyEnabled($user->church)) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('messaging.view') || $user->can('messaging.send');
    }

    public function getBreadcrumb(): string
    {
        return __('Messages');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['recipients', 'createdBy', 'outboundMessages', 'task.template']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('recipients')
                    ->disabledClick()
                    ->label(__('To'))
                    ->html()
                    ->state(fn (EventMessage $record): array => $record->recipients
                        ->map(fn (User $recipient): string => UserAvatar::html($recipient)->toHtml())
                        ->all())
                    ->listWithLineBreaks()
                    ->limitList(3),
                TextColumn::make('task_name')
                    ->label(__('Task'))
                    ->state(fn (EventMessage $record): ?string => $record->task?->displayName())
                    ->placeholder('—'),
                TextColumn::make('responses')
                    ->label(__('Response'))
                    ->state(fn (EventMessage $record): string => view('filament.messaging.response-statuses', [
                        'rows' => $this->responseRows($record),
                        'compact' => $record->event_task_id !== null,
                    ])->render())
                    ->html(),
                TextColumn::make('status')
                    ->label(__('Delivery'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        EventMessage::STATUS_SENT => 'success',
                        EventMessage::STATUS_PARTIAL => 'warning',
                        EventMessage::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('outboundMessages.channel')
                    ->label(__('Sent via'))
                    ->badge()
                    ->distinctList()
                    ->formatStateUsing(fn (string $state): string => MessagePreference::tryFrom($state)?->label() ?? $state)
                    ->placeholder('—'),
                TextColumn::make('createdBy.display_name')
                    ->disabledClick()
                    ->label(__('By'))
                    ->html()
                    ->formatStateUsing(fn ($state, EventMessage $record) => $record->createdBy
                        ? UserAvatar::html($record->createdBy)
                        : '—')
                    ->placeholder('—'),
                TextColumn::make('sent_at')
                    ->label(__('Sent'))
                    ->dateTime('j M Y, H:i')
                    ->placeholder(__('Not sent yet'))
                    ->tooltip(__('Updated when the message is resent'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime('j M Y, H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add message'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => $this->canCreateMessage())
                    ->modalHeading(__('Add message for this event'))
                    ->modalWidth('3xl')
                    ->createAnother(false)
                    ->modalSubmitActionLabel(__('Send'))
                    ->modalSubmitAction(fn (Action $action): Action => $action
                        ->disabled(EventMessageForm::unacceptedTaskIds($this->getOwnerRecord()) === []))
                    ->form(fn (): array => EventMessageForm::components($this->getOwnerRecord()))
                    ->using(function (array $data): EventMessage {
                        /** @var Event $event */
                        $event = $this->getOwnerRecord();

                        return EventMessageForm::create($event, $data);
                    })
                    ->successNotificationTitle(__('Message sent')),
            ])
            ->recordActions([
                Action::make('viewBody')
                    ->label(__('Preview'))
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (EventMessage $record) => $record->subject ?: __('Message'))
                    ->modalContent(fn (EventMessage $record) => view('filament.events.message-preview', [
                        'message' => $record->loadMissing('recipients'),
                        'previews' => app(EventMessageService::class)->previews($record),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalWidth('3xl')
                    ->extraModalFooterActions(fn (): array => [
                        Action::make('resend')
                            ->label(__('Resend'))
                            ->icon(Heroicon::OutlinedArrowPath)
                            ->visible(fn (): bool => $this->canCreateMessage())
                            ->requiresConfirmation()
                            ->modalHeading(__('Resend message?'))
                            ->modalDescription(__('This delivers the message again to every recipient, including those who already received it.'))
                            ->action(function (EventMessage $record): void {
                                $message = app(EventMessageService::class)->resend($record);

                                $notification = Notification::make()->title(match ($message->status) {
                                    EventMessage::STATUS_SENT => __('Message resent'),
                                    EventMessage::STATUS_PARTIAL => __('Message resent, but some deliveries failed'),
                                    default => __('Resending failed'),
                                });

                                match ($message->status) {
                                    EventMessage::STATUS_SENT => $notification->success(),
                                    EventMessage::STATUS_PARTIAL => $notification->warning(),
                                    default => $notification->danger(),
                                };

                                $notification->send();
                            })
                            ->cancelParentActions(),
                    ]),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->canCreateMessage()),
            ])
            ->emptyStateHeading(__('No messages yet'))
            ->emptyStateDescription(__('Add a message to notify people assigned on this event.'))
            ->emptyStateIcon(Heroicon::OutlinedEnvelope);
    }

    /**
     * Each recipient's current answer for every task they have on this event.
     *
     * @return list<array{user: User, task: EventTask, state: AssignmentState}>
     */
    protected function responseRows(EventMessage $message): array
    {
        /** @var Event $event */
        $event = $this->getOwnerRecord();
        $event->loadMissing(['tasks.responses', 'tasks.assignedTeam', 'tasks.template']);
        $this->taskStates ??= app(AssignmentStateResolver::class)->forMany($event->tasks);
        $messages = app(EventMessageService::class);
        $rows = [];

        foreach ($message->recipients as $recipient) {
            $tasks = $messages->tasksForRecipient($event, $recipient);

            if ($message->event_task_id !== null) {
                $tasks = $tasks->where('id', $message->event_task_id);
            }

            foreach ($tasks as $task) {
                $rows[] = [
                    'user' => $recipient,
                    'task' => $task,
                    'state' => $this->taskStates[$task->id],
                ];
            }
        }

        return $rows;
    }

    protected function canCreateMessage(): bool
    {
        return EventMessageForm::canSend();
    }
}
