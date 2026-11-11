<?php

namespace App\Filament\Tables;

use App\Enums\AssignmentState;
use App\Filament\Resources\Events\Actions\ViewEventTaskAction;
use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\User;
use App\Services\AssignmentResponder;
use App\Services\AssignmentStateResolver;
use App\Support\DateTimeFormats;
use App\Support\UserAvatar;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class AssignedTasksTable
{
    /**
     * @param  array{show_assignee?: bool, schedule?: bool}  $options
     */
    public static function configure(Table $table, array $options = []): Table
    {
        $showAssignee = (bool) ($options['show_assignee'] ?? false);
        $asSchedule = (bool) ($options['schedule'] ?? false);

        return $table
            ->defaultSort($asSchedule
                ? fn (Builder $query, string $direction): Builder => self::orderScheduleQuery($query, $direction)
                : 'event.starts_at')
            ->columns($asSchedule ? self::scheduleColumns() : [
                TextColumn::make('event.starts_at')
                    ->label(__('Service date'))
                    ->date(DateTimeFormats::dateWithWeekday())
                    ->sortable(),
                TextColumn::make('event.name')
                    ->label(__('Service'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('task')
                    ->label(__('Task'))
                    ->state(fn (EventTask $record): string => $record->displayName())
                    ->description(function (EventTask $record): ?string {
                        if ($record->isGlobal()) {
                            return __('Whole service');
                        }

                        $time = filled($record->starts_at)
                            ? DateTimeFormats::formatTime((string) $record->starts_at)
                            : null;

                        return $time ? __('Starts :time', ['time' => $time]) : null;
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $inner) use ($search): void {
                            $inner->where('name', 'like', "%{$search}%")
                                ->orWhereHas('template', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"));
                        });
                    }),
                TextColumn::make('assignment_state')
                    ->label(__('Status'))
                    ->state(fn (EventTask $record): AssignmentState => app(AssignmentStateResolver::class)->for($record))
                    ->formatStateUsing(fn (AssignmentState $state): HtmlString => new HtmlString(Blade::render(
                        '<x-assignment-status :state="$state" />',
                        ['state' => $state],
                    ))),
                TextColumn::make('assignedUser.display_name')
                    ->disabledClick()
                    ->label(__('Person'))
                    ->html()
                    ->formatStateUsing(fn ($state, EventTask $record) => $record->assignedUser
                        ? UserAvatar::html($record->assignedUser)
                        : '—')
                    ->placeholder('—')
                    ->toggleable()
                    ->visible($showAssignee),
                TextColumn::make('assignedTeam.name')
                    ->label(__('Team'))
                    ->placeholder('—')
                    ->toggleable()
                    ->visible($showAssignee),
                TextColumn::make('song')
                    ->placeholder('—')
                    ->toggleable()
                    ->limit(40),
            ])
            ->filters([
                Filter::make('from_date')
                    ->label(__('From date'))
                    ->default([
                        'date' => now()->toDateString(),
                    ])
                    ->schema([
                        DatePicker::make('date')
                            ->label(__('Show tasks from'))
                            ->native(false)
                            ->default(fn (): string => now()->toDateString())
                            ->helperText(__('Default is today onwards. Clear to include past services.')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['date'] ?? null)) {
                            return $query;
                        }

                        $from = Carbon::parse($data['date'])->toDateString();

                        return $query->whereHas(
                            'event',
                            fn (Builder $eventQuery) => $eventQuery->whereDate('starts_at', '>=', $from)
                        );
                    })
                    ->indicateUsing(function (array $data): array {
                        if (blank($data['date'] ?? null)) {
                            return [];
                        }

                        $from = Carbon::parse($data['date'])->toDateString();

                        // Default is "today onward" — do not paint a chip for the quiet default.
                        if ($from === now()->toDateString()) {
                            return [];
                        }

                        $label = Carbon::parse($from)->translatedFormat(DateTimeFormats::date());

                        return [
                            Indicator::make(__('From :date', ['date' => $label]))
                                ->removeField('date'),
                        ];
                    }),
            ])
            ->filtersTriggerAction(
                fn (Action $action) => $action
                    ->iconButton()
                    ->icon(Heroicon::OutlinedFunnel)
                    ->tooltip(__('Filter tasks')),
            )
            ->recordActions([
                Action::make('iCanHelp')
                    ->label(__('I can help'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->button()
                    ->visible(fn (EventTask $record): bool => $asSchedule && self::canOfferResponse($record))
                    ->action(function (EventTask $record): void {
                        /** @var User $user */
                        $user = auth()->user();
                        app(AssignmentResponder::class)->confirm($record, $user);
                        Notification::make()->success()->title(__('Thanks — you\'re confirmed'))->send();
                    }),
                Action::make('iCantMakeIt')
                    ->label(__("I can't make it"))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->button()
                    ->visible(fn (EventTask $record): bool => $asSchedule && self::canOfferResponse($record))
                    ->schema([
                        Textarea::make('note')
                            ->label(__('Optional reason'))
                            ->rows(3),
                    ])
                    ->modalSubmitActionLabel(__("I can't make it"))
                    ->action(function (EventTask $record, array $data): void {
                        /** @var User $user */
                        $user = auth()->user();
                        app(AssignmentResponder::class)->decline($record, $user, $data['note'] ?? null);
                        Notification::make()->success()->title(__('Response recorded'))->send();
                    }),
                ViewEventTaskAction::makeTableAction()
                    ->visible(fn (EventTask $record): bool => $record->event !== null
                        && (auth()->user()?->can('view', $record->event) ?? false)),
                Action::make('openEvent')
                    ->label(__('Open service'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(function (EventTask $record): string {
                        $event = $record->event;
                        $user = auth()->user();
                        $page = ($event !== null && $user?->can('update', $event))
                            ? 'edit'
                            : 'view';

                        return EventResource::getUrl($page, [
                            'record' => $record->event_id,
                        ]);
                    })
                    ->visible(fn (EventTask $record): bool => $record->event !== null
                        && (auth()->user()?->can('view', $record->event) ?? false)),
            ])
            ->emptyStateHeading(__('No assigned tasks'))
            ->emptyStateDescription(__('Nothing is scheduled from this date onward.'));
    }

    protected static function canOfferResponse(EventTask $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $responder = app(AssignmentResponder::class);

        if (! $responder->canSelfRespond($record, $user)) {
            return false;
        }

        $state = app(AssignmentStateResolver::class)->for($record);

        return in_array($state, [
            AssignmentState::Invited,
            AssignmentState::Draft,
            AssignmentState::Informed,
            AssignmentState::AssumedTeam,
            AssignmentState::Confirmed,
            AssignmentState::Declined,
        ], true);
    }

    protected static function orderScheduleQuery(Builder $query, string $direction): Builder
    {
        $pendingRank = <<<'SQL'
            COALESCE((
                SELECT CASE
                    WHEN status = 'invited' THEN 0
                    WHEN status = 'declined' THEN 1
                    WHEN status = 'confirmed' THEN 3
                    WHEN status = 'cancelled' THEN 4
                    ELSE 2
                END
                FROM event_task_responses
                WHERE event_task_id = event_tasks.id
                  AND status IN ('invited', 'confirmed', 'declined', 'cancelled')
                ORDER BY occurred_at DESC, id DESC
                LIMIT 1
            ), CASE WHEN event_tasks.assigned_user_id IS NOT NULL THEN 2 ELSE 3 END)
            SQL;

        return $query
            ->orderByRaw($pendingRank.' ASC')
            ->orderBy(
                Event::query()->select('starts_at')->whereColumn('events.id', 'event_tasks.event_id')->limit(1),
                $direction,
            );
    }

    /**
     * One stacked row per assignment: readable on a phone without sideways scrolling.
     *
     * @return list<Stack>
     */
    private static function scheduleColumns(): array
    {
        return [
            Stack::make([
                ViewColumn::make('schedule')
                    ->label(__('My schedule'))
                    ->view('filament.tables.schedule-row')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $inner) use ($search): void {
                            $inner->where('name', 'like', "%{$search}%")
                                ->orWhereHas('template', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                                ->orWhereHas('event', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"));
                        });
                    }),
            ]),
        ];
    }
}
