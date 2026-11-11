<?php

namespace App\Filament\Resources\Events\Actions;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Events\Schemas\EventTaskForm;
use App\Models\Event;
use App\Models\EventTask;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Shared event-task edit modal — used by service order and task spreadsheet.
 */
class EditEventTaskAction
{
    /**
     * Page / standalone action (spreadsheet). Pass taskId in mount arguments.
     */
    public static function makePageAction(?callable $after = null): Action
    {
        return Action::make('editEventTask')
            ->label(__('Edit task'))
            ->modalHeading(fn (array $arguments): string => static::heading($arguments))
            ->modalDescription(fn (array $arguments): ?string => static::description($arguments))
            ->modalSubmitActionLabel(__('Save changes'))
            ->modalWidth('3xl')
            ->modalSubmitAction(fn (Action $action): Action => $action->color('gray'))
            ->extraModalFooterActions(fn (Action $action, array $arguments): array => [
                static::saveAndMessageButton($action, static::resolveTask($arguments)),
            ])
            ->icon(Heroicon::OutlinedPencilSquare)
            ->fillForm(function (array $arguments): array {
                $task = static::resolveTask($arguments);

                return EventTaskForm::fillFromRecord($task) + [
                    'expected_updated_at' => $task->updated_at?->toJSON(),
                ];
            })
            ->schema(function (Schema $schema, array $arguments): Schema {
                $task = static::resolveTask($arguments);

                return $schema->dense()->columns(['default' => 1, 'md' => 2])->components([
                    Hidden::make('expected_updated_at'),
                    ...EventTaskForm::components($task->event),
                ]);
            })
            ->action(function (array $data, array $arguments, Action $action, HasActions $livewire) use ($after): void {
                $task = static::resolveTask($arguments);
                $expected = $data['expected_updated_at'] ?? null;
                unset($data['expected_updated_at']);

                if (filled($expected) && $task->updated_at !== null && $task->updated_at->toJSON() !== $expected) {
                    Notification::make()
                        ->danger()
                        ->title(__('This assignment was changed by someone else'))
                        ->body(__('Your changes were not saved. Open the cell again to review the latest assignment.'))
                        ->send();

                    $action->halt();

                    return;
                }

                EventTaskForm::persist($task, $data, $task->event);

                Notification::make()
                    ->success()
                    ->title(__('Assignment saved'))
                    ->send();

                if ($after) {
                    $after($task, $arguments);
                }

                if ($arguments['sendMessage'] ?? false) {
                    SendTaskMessageAction::openAfterSave($livewire, $task->fresh());
                }
            });
    }

    /**
     * Table record edit action (service-order relation manager).
     */
    public static function makeTableAction(Event $event): EditAction
    {
        return EditAction::make()
            ->modalHeading(fn (EventTask $record): string => __('Edit task — :name', ['name' => $record->displayName()]))
            ->modalWidth('3xl')
            ->modalSubmitAction(fn (Action $action): Action => $action->color('gray'))
            ->mutateRecordDataUsing(fn (array $data, EventTask $record): array => EventTaskForm::fillFromRecord($record))
            ->mutateFormDataUsing(fn (array $data): array => EventTaskForm::normalize($data, $event))
            ->using(fn (array $data, EventTask $record): EventTask => EventTaskForm::persist($record, $data, $event))
            ->extraModalFooterActions(fn (EditAction $action, EventTask $record): array => [
                static::saveAndMessageButton($action, $record),
            ])
            ->after(function (array $arguments, EventTask $record, HasActions $livewire): void {
                if ($arguments['sendMessage'] ?? false) {
                    SendTaskMessageAction::openAfterSave($livewire, $record);
                }
            });
    }

    /**
     * Saves like the normal submit button, then opens the message popup with the task's person or team selected.
     */
    public static function saveAndMessageButton(Action $action, EventTask|Event $taskOrEvent): Action
    {
        $event = $taskOrEvent instanceof EventTask ? $taskOrEvent->event : $taskOrEvent;

        return $action->makeModalSubmitAction('saveAndMessage', arguments: ['sendMessage' => true])
            ->label(__('Save and send message'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->visible($event !== null && SendTaskMessageAction::isAvailableFor($event));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected static function resolveTask(array $arguments): EventTask
    {
        $taskId = (int) ($arguments['taskId'] ?? 0);
        $churchId = app(CurrentChurch::class)->id() ?? auth()->user()?->church_id;

        $query = EventTask::query()
            ->with(['event.church', 'template', 'pictures', 'sharedFiles', 'assignedUser', 'assignedTeam']);

        $query->whereHas('event', fn ($q) => $q->where('church_id', $churchId ?? 0));

        $task = $query->find($taskId);

        if ($task === null) {
            throw (new ModelNotFoundException)->setModel(EventTask::class, [$taskId]);
        }

        return $task;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected static function heading(array $arguments): string
    {
        $task = static::resolveTask($arguments);

        return __('Edit task — :name', ['name' => $task->displayName()]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected static function description(array $arguments): ?string
    {
        $task = static::resolveTask($arguments);
        $event = $task->event;

        if ($event === null) {
            return null;
        }

        $when = collect([
            $event->starts_at?->translatedFormat('D j M Y'),
            $event->starts_at?->format('H:i'),
            $event->name,
        ])->filter()->implode(' · ');

        return $when !== '' ? $when : null;
    }
}
