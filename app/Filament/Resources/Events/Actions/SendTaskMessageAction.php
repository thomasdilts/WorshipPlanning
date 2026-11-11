<?php

namespace App\Filament\Resources\Events\Actions;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Events\Schemas\EventMessageForm;
use App\Models\Event;
use App\Models\EventTask;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Messaging\Support\MessagingAvailability;

/**
 * "Add message" popup for one task, with its person or team already selected. Pass taskId in the arguments.
 */
class SendTaskMessageAction
{
    public static function make(): Action
    {
        return Action::make('sendTaskMessage')
            // Mountable from any page that registers it, so check the send permission here and
            // not only on the button that opens it.
            ->authorize(fn (array $arguments): bool => static::isAvailableFor(static::resolveTask($arguments)->event))
            ->label(__('Send message'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->modalHeading(fn (array $arguments): string => __('Send message — :task', [
                'task' => static::resolveTask($arguments)->displayName(),
            ]))
            ->modalWidth('3xl')
            ->modalSubmitActionLabel(__('Send'))
            ->schema(fn (array $arguments): array => EventMessageForm::components(static::resolveTask($arguments)->event))
            ->fillForm(function (array $arguments): array {
                $task = static::resolveTask($arguments);

                return [
                    'recipient_task_ids' => filled($task->assigned_user_id) ? [$task->id] : [],
                    'team_task_ids' => blank($task->assigned_user_id) && filled($task->assigned_team_id) ? [$task->id] : [],
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $task = static::resolveTask($arguments);
                abort_unless(static::isAvailableFor($task->event), 403);

                try {
                    EventMessageForm::create($task->event, $data);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('Message sent'))
                    ->send();
            });
    }

    /**
     * "Save and send message" is offered when the church can message and the user may send.
     */
    public static function isAvailableFor(Event $event): bool
    {
        $event->loadMissing('church');

        return ! $event->is_template
            && app(MessagingAvailability::class)->anyEnabled($event->church)
            && EventMessageForm::canSend();
    }

    /**
     * Opens the message popup after a task was saved, or explains why there is nobody to message.
     */
    public static function openAfterSave(HasActions $livewire, EventTask $task): void
    {
        if (blank($task->assigned_user_id) && blank($task->assigned_team_id)) {
            Notification::make()
                ->warning()
                ->title(__('No person or team on this task to message'))
                ->send();

            return;
        }

        $livewire->replaceMountedAction('sendTaskMessage', ['taskId' => $task->id]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function resolveTask(array $arguments): EventTask
    {
        $taskId = (int) ($arguments['taskId'] ?? 0);
        $churchId = app(CurrentChurch::class)->id() ?? auth()->user()?->church_id;

        $task = EventTask::query()
            ->with(['event.church', 'template'])
            ->whereHas('event', fn ($query) => $query->where('church_id', $churchId ?? 0))
            ->find($taskId);

        if ($task === null) {
            throw (new ModelNotFoundException)->setModel(EventTask::class, [$taskId]);
        }

        return $task;
    }
}
