<?php

namespace App\Filament\Resources\Events\Actions;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Events\Schemas\EventTaskForm;
use App\Models\Event;
use App\Models\EventTask;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;

/**
 * Read-only event-task modal — same fields as edit, no save.
 */
class ViewEventTaskAction
{
    /**
     * Page / standalone action (My tasks, spreadsheet). Pass taskId in mount arguments.
     */
    public static function makePageAction(): Action
    {
        return Action::make('viewEventTask')
            ->label(__('View task'))
            ->modalHeading(fn (array $arguments): string => static::heading($arguments))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->disabledSchema()
            ->fillForm(function (array $arguments): array {
                $task = static::resolveTask($arguments);

                return EventTaskForm::fillFromRecord($task);
            })
            ->schema(function (array $arguments): array {
                $task = static::resolveTask($arguments);

                return EventTaskForm::components($task->event);
            });
    }

    /**
     * Table record view action (service order, assigned-task lists).
     */
    public static function makeTableAction(?Event $event = null): ViewAction
    {
        return ViewAction::make()
            ->label(__('View'))
            ->modalHeading(fn (EventTask $record): string => 'Task — '.$record->displayName())
            ->mutateRecordDataUsing(fn (array $data, EventTask $record): array => EventTaskForm::fillFromRecord($record))
            ->schema(function (EventTask $record) use ($event): array {
                $owner = $event ?? $record->event;

                return EventTaskForm::components($owner);
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected static function resolveTask(array $arguments): EventTask
    {
        $taskId = (int) ($arguments['taskId'] ?? 0);
        $churchId = app(CurrentChurch::class)->id() ?? auth()->user()?->church_id;

        return EventTask::query()
            ->with(['event.church', 'template', 'pictures', 'sharedFiles', 'assignedUser', 'assignedTeam'])
            ->whereHas('event', fn ($query) => $query->where('church_id', $churchId ?? 0))
            ->findOrFail($taskId);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected static function heading(array $arguments): string
    {
        $task = static::resolveTask($arguments);

        return __('Task — :name', ['name' => $task->displayName()]);
    }
}
