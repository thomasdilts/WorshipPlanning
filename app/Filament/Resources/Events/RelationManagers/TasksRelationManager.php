<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Resources\Events\Actions\EditEventTaskAction;
use App\Filament\Resources\Events\Actions\SendTaskMessageAction;
use App\Filament\Resources\Events\Actions\ViewEventTaskAction;
use App\Filament\Resources\Events\Concerns\HasEventMessageActions;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Schemas\EventTaskForm;
use App\Models\Event;
use App\Models\EventTask;
use App\Services\EventTaskRowSummary;
use App\Services\EventTaskTimeline;
use App\Support\DateTimeFormats;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Shared service-order table. Subclasses set {@see $globalOnly} for Order vs People.
 */
class TasksRelationManager extends RelationManager
{
    use HasEventMessageActions;

    protected static string $relationship = 'tasks';

    /**
     * null = all, true = whole-service (People), false = timed (Order).
     */
    protected static ?bool $globalOnly = null;

    /**
     * Sortable key of the start-time divider row in the Order table.
     */
    public const START_MARKER_KEY = 'start-marker';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return match (static::$globalOnly) {
            true => __('People'),
            false => __('Order'),
            default => __('Service order'),
        };
    }

    public function form(Schema $schema): Schema
    {
        /** @var Event $event */
        $event = $this->getOwnerRecord();

        return EventTaskForm::configure($schema, $event);
    }

    public function table(Table $table): Table
    {
        /** @var Event $event */
        $event = $this->getOwnerRecord();
        $summary = app(EventTaskRowSummary::class);
        $canUpdate = auth()->user()?->can('update', $event) ?? false;
        $isOrder = static::$globalOnly === false;

        $table = $table
            ->recordTitleAttribute('name')
            ->header(fn () => view('filament.events.workspace-toolbar', [
                'isOrder' => $isOrder,
                'startTime' => DateTimeFormats::formatTime($event->starts_at?->timezone(config('app.timezone'))),
            ]))
            ->recordClasses(fn (EventTask $record): ?string => $isOrder && $record->before_start ? 'wp-order-before' : null)
            ->paginated(false)
            ->emptyStateHeading($isOrder ? __('Empty service order') : __('No whole-service responsibilities'))
            ->emptyStateDescription($isOrder
                ? __('Add timed tasks to build the run-of-show.')
                : __('Whole-service roles (people and teams for the entire service) appear here.'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->scopeTasksQuery($query))
            ->columns([
                ViewColumn::make('drag_handle')
                    ->label('')
                    ->disabledClick()
                    ->view('filament.tables.order-drag-handle')
                    ->visible($isOrder && $canUpdate && ! $this->isReadOnly()),
                TextColumn::make('starts_at')
                    ->label(__('Start'))
                    ->visible($isOrder)
                    ->formatStateUsing(function ($state, EventTask $record): string {
                        if ($record->isGlobal()) {
                            return '';
                        }

                        if (blank($state)) {
                            return '—';
                        }

                        return (string) DateTimeFormats::formatTime((string) $state);
                    }),
                TextColumn::make('duration')
                    ->label(__('Mins'))
                    ->visible($isOrder)
                    ->formatStateUsing(function ($state, EventTask $record): string {
                        if ($record->isGlobal()) {
                            return '—';
                        }

                        return $state ? (string) $state : '—';
                    }),
                TextColumn::make('displayName')
                    ->label($isOrder ? __('Task') : __('Responsibility'))
                    ->action($this->isReadOnly() ? ViewEventTaskAction::makeTableAction($event) : null)
                    ->state(fn (EventTask $record): string => $record->displayName())
                    ->description(function (EventTask $record) use ($isOrder): ?string {
                        if (! $isOrder) {
                            return $record->template?->name !== $record->displayName()
                                ? $record->template?->name
                                : null;
                        }

                        if ($record->template?->name !== $record->displayName()) {
                            return __('Task type:').' '.$record->template?->name;
                        }

                        return null;
                    }),
                TextColumn::make('summary')
                    ->label(__('Details'))
                    ->disabledClick()
                    ->state(fn (EventTask $record): string => $summary->html($record, $event)->toHtml())
                    ->html()
                    ->wrap(),
            ])
            ->headerActions([
                Action::make('printPdf')
                    ->label(__('Print PDF'))
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->url(fn (): string => route('events.pdf', $event))
                    ->openUrlInNewTab()
                    ->visible(! $event->is_template),
                $this->eventMessagesAction($event),
                ActionGroup::make([
                    Action::make('editDetails')
                        ->label(__('Edit service details'))
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->url(fn (): string => EventResource::getUrl('edit-details', ['record' => $event])),
                    Action::make('viewReadonly')
                        ->label(__('View'))
                        ->icon(Heroicon::OutlinedEye)
                        ->url(fn (): string => EventResource::getUrl('view', ['record' => $event])),
                    DeleteAction::make('deleteEvent')
                        ->label($event->is_template ? __('Delete template') : __('Delete service'))
                        ->model(Event::class)
                        ->record($event)
                        ->authorize(fn (): bool => auth()->user()?->can('delete', $event) ?? false)
                        ->successRedirectUrl(fn (): string => EventResource::getUrl($event->is_template ? 'templates' : 'index')),
                ])
                    ->label(__('More'))
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->button()
                    ->color('gray')
                    ->visible($canUpdate && ! $this->isReadOnly()),
                Action::make('openWorkspace')
                    ->label(__('Edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->button()
                    ->color('gray')
                    ->url(fn (): string => EventResource::getUrl('edit', ['record' => $event]))
                    ->visible($canUpdate && $this->isReadOnly()),
                CreateAction::make()
                    ->label($isOrder ? __('Add task') : __('Add responsibility'))
                    ->visible(fn (): bool => $canUpdate && ! $this->isReadOnly())
                    ->createAnother(false)
                    ->extraModalFooterActions(fn (CreateAction $action): array => [
                        EditEventTaskAction::saveAndMessageButton($action, $event),
                    ])
                    ->after(function (array $arguments, EventTask $record, HasActions $livewire): void {
                        if ($arguments['sendMessage'] ?? false) {
                            SendTaskMessageAction::openAfterSave($livewire, $record);
                        }
                    })
                    ->using(function (array $data): EventTask {
                        /** @var Event $event */
                        $event = $this->getOwnerRecord();

                        return EventTaskForm::create($event, $data);
                    }),
            ])
            ->recordActions([
                Action::make('moveUp')
                    ->label(__('Move up'))
                    ->icon(Heroicon::OutlinedArrowUp)
                    ->iconButton()
                    ->color('gray')
                    ->visible(fn (EventTask $record): bool => $canUpdate && $isOrder && ! $this->isReadOnly() && ! $record->isGlobal())
                    ->action(fn (EventTask $record) => $this->moveTask($record, -1)),
                Action::make('moveDown')
                    ->label(__('Move down'))
                    ->icon(Heroicon::OutlinedArrowDown)
                    ->iconButton()
                    ->color('gray')
                    ->visible(fn (EventTask $record): bool => $canUpdate && $isOrder && ! $this->isReadOnly() && ! $record->isGlobal())
                    ->action(fn (EventTask $record) => $this->moveTask($record, 1)),
                EditEventTaskAction::makeTableAction($event)
                    ->visible(fn (): bool => $canUpdate),
                DeleteAction::make()
                    ->visible(fn (): bool => $canUpdate)
                    ->after(function () use ($event): void {
                        app(EventTaskTimeline::class)->recalculate($event->fresh(['tasks.template']));
                    }),
            ]);

        if ($isOrder) {
            $table
                ->reorderable('sort_order', $canUpdate && ! $this->isReadOnly())
                ->reorderRecordsTriggerAction(fn (Action $action): Action => $action->extraAttributes(['class' => 'wp-order-native-reorder']))
                ->beforeReordering(function (array $order): void {
                    $expected = $this->getTable()->getQuery()->pluck('event_tasks.id')->map(fn (int $id): string => (string) $id)->sort()->values()->all();
                    $submitted = collect($order)->map(fn (mixed $id): string => (string) $id)->sort()->values()->all();
                    abort_unless($expected === $submitted, 422);
                })
                ->afterReordering(function () use ($event): void {
                    app(EventTaskTimeline::class)->recalculate($event->fresh(['tasks.template']));
                });
        }

        return $table;
    }

    protected function scopeTasksQuery(Builder $query): Builder
    {
        $query
            ->with([
                'template',
                'assignedUser',
                'assignedTeam',
                'sharedFiles',
                'pictures',
                'catalogSong',
                'responses',
            ]);

        if (static::$globalOnly === true) {
            $query
                ->whereHas('template', fn (Builder $q) => $q->where('use_globally', true))
                ->orderByRaw('(SELECT default_global_order FROM task_templates WHERE task_templates.id = event_tasks.task_template_id)')
                ->orderBy('event_tasks.id');
        } elseif (static::$globalOnly === false) {
            $query
                ->where(function (Builder $q): void {
                    $q->whereDoesntHave('template')
                        ->orWhereHas('template', fn (Builder $inner) => $inner->where('use_globally', false));
                })
                ->orderByDesc('event_tasks.before_start')
                ->orderBy('event_tasks.sort_order')
                ->orderBy('event_tasks.id');
        } else {
            $query
                ->orderByRaw('(SELECT COALESCE(use_globally, 0) FROM task_templates WHERE task_templates.id = event_tasks.task_template_id) DESC')
                ->orderByDesc('event_tasks.before_start')
                ->orderByRaw('CASE WHEN (SELECT COALESCE(use_globally, 0) FROM task_templates WHERE task_templates.id = event_tasks.task_template_id) = 1 THEN (SELECT default_global_order FROM task_templates WHERE task_templates.id = event_tasks.task_template_id) ELSE event_tasks.sort_order END')
                ->orderBy('event_tasks.id');
        }

        return $query;
    }

    /**
     * The Order table also sends the start-time divider's position; tasks above it run before the start.
     *
     * @param  array<int, int|string>  $order
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        $order = array_map(fn (int|string $key): string => (string) $key, array_values($order));
        $markerIndex = array_search(static::START_MARKER_KEY, $order, true);

        if (static::$globalOnly !== false || $markerIndex === false || ! $this->getTable()->isReorderable()) {
            parent::reorderTable($order, $draggedRecordKey);

            return;
        }

        $taskOrder = array_values(array_filter($order, fn (string $key): bool => $key !== static::START_MARKER_KEY));
        $beforeIds = array_slice($order, 0, $markerIndex);
        $draggedRecordKey = (string) $draggedRecordKey === static::START_MARKER_KEY ? null : $draggedRecordKey;

        DB::transaction(function () use ($taskOrder, $beforeIds, $draggedRecordKey): void {
            $this->getTable()->callBeforeReordering($taskOrder);

            $this->getTable()->getQuery()->update([
                'before_start' => DB::raw(count($beforeIds) > 0
                    ? 'CASE WHEN event_tasks.id IN ('.implode(',', array_map('intval', $beforeIds)).') THEN 1 ELSE 0 END'
                    : '0'),
            ]);

            parent::reorderTable($taskOrder, $draggedRecordKey);
        });
    }

    /**
     * Moves one step up or down. Stepping past the start time moves the task to the other side of it.
     */
    protected function moveTask(EventTask $task, int $direction): void
    {
        /** @var Event $event */
        $event = $this->getOwnerRecord();
        abort_unless(auth()->user()?->can('update', $event) && ! $this->isReadOnly(), 403);

        $timeline = app(EventTaskTimeline::class);
        $siblings = $timeline->timedTasks($event);
        $index = $siblings->search(fn (EventTask $row): bool => $row->id === $task->id);

        if ($index === false) {
            return;
        }

        $rows = $siblings->all();
        /** @var EventTask $current */
        $current = $rows[$index];
        $neighbour = $rows[$index + $direction] ?? null;

        if ($neighbour !== null && $neighbour->before_start === $current->before_start) {
            [$rows[$index], $rows[$index + $direction]] = [$neighbour, $current];
        } elseif ($direction < 0 && ! $current->before_start) {
            $current->before_start = true;
        } elseif ($direction > 0 && $current->before_start) {
            $current->before_start = false;
        } else {
            return;
        }

        DB::transaction(function () use ($rows): void {
            foreach (array_values($rows) as $position => $row) {
                $row->sort_order = $position + 1;
                $row->save();
            }
        });

        $timeline->recalculate($event->fresh(['tasks.template']));
    }

    public function sendTaskMessageAction(): Action
    {
        return SendTaskMessageAction::make();
    }
}
