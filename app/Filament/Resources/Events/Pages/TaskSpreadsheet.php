<?php

namespace App\Filament\Resources\Events\Pages;

use App\Core\Tenancy\CurrentChurch;
use App\Enums\AssignmentState;
use App\Enums\SpreadsheetRangeKey;
use App\Enums\SpreadsheetViewKey;
use App\Filament\Resources\Events\Actions\EditEventTaskAction;
use App\Filament\Resources\Events\Actions\SendTaskMessageAction;
use App\Filament\Resources\Events\EventResource;
use App\Models\Church;
use App\Models\EventTask;
use App\Models\User;
use App\Services\TaskSpreadsheetBuilder;
use App\Services\TaskSpreadsheetPreferences;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Spatie\Permission\PermissionRegistrar;

class TaskSpreadsheet extends Page implements HasTable
{
    use InteractsWithTable {
        updatedTableFilters as protected updateFilamentTableFilters;
    }

    /** @var array<string, string> */
    protected array $extraBodyAttributes = [
        'class' => 'wp-spreadsheet',
    ];

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static string $resource = EventResource::class;

    #[Url(as: 'view')]
    public string $staffingView = 'all_staffing';

    #[Url]
    public string $range = 'next_six_weeks';

    /** @var array<string, mixed>|null */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'search')]
    public $tableSearch = '';

    public ?string $customFrom = null;

    public ?string $customUntil = null;

    public ?string $focusCell = null;

    public function getTitle(): string|Htmlable
    {
        return __('Tasks by event');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    protected string $view = 'filament-panels::pages.page';

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    /** @var array{staffing_columns: list<array{id: int, name: string, key: string}>, extra_columns: list<array{id: int, name: string, key: string}>, rows: Collection}|null */
    protected ?array $matrix = null;

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->church_id === null || ! EventResource::canViewAny()) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('events.tasks_by_event');
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $user = auth()->user();
        $church = $this->church();
        $this->tableFilters ??= session()->get($this->getTableFiltersSessionKey());

        if ($user instanceof User && $church !== null) {
            $prefs = app(TaskSpreadsheetPreferences::class)->for($user, $church);

            if (! request()->has('view')) {
                $this->staffingView = $prefs['view'];
            }
            if (! request()->has('range')) {
                $this->range = $prefs['range'];
            }

            $this->customFrom = $prefs['from'];
            $this->customUntil = $prefs['until'];
        }

        if (array_key_exists('custom_dates', $this->tableFilters ?? [])) {
            $this->customFrom = $this->tableFilters['custom_dates']['from'] ?? null;
            $this->customUntil = $this->tableFilters['custom_dates']['until'] ?? null;
        }

        $this->matrix = $this->buildMatrix();
    }

    public function getTableFiltersSessionKey(): string
    {
        return 'tasks-by-event.filters.'.auth()->id().'.'.$this->church()?->id;
    }

    public function getTableSearchSessionKey(): string
    {
        return 'tasks-by-event.search.'.auth()->id().'.'.$this->church()?->id;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                SchemaView::make('filament.events.task-spreadsheet-chrome'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function createEventAction(): Action
    {
        return Action::make('createEvent')
            ->label(__('Create event'))
            ->icon(Heroicon::OutlinedPlus)
            ->url(EventResource::getUrl('create'))
            ->visible(fn (): bool => EventResource::canCreate());
    }

    public function exportSpreadsheetAction(): Action
    {
        return Action::make('exportSpreadsheet')
            ->label(__('Export spreadsheet'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->url(fn (): string => route('schedule.staffing-csv', array_filter([
                'view' => $this->staffingView,
                'range' => $this->range,
                'from' => $this->customFrom,
                'until' => $this->customUntil,
            ], fn (mixed $value): bool => filled($value))))
            ->color('gray');
    }

    /**
     * Same modal as the service-order task editor.
     */
    public function editEventTaskAction(): Action
    {
        return EditEventTaskAction::makePageAction(function (EventTask $task, array $arguments = []): void {
            if (is_string($arguments['focusCell'] ?? null)) {
                $this->focusCell = $arguments['focusCell'];
            }

            $this->refreshSpreadsheet();
            $this->js($this->focusCellScript());
        });
    }

    public function chooseEventTaskAction(): Action
    {
        return Action::make('chooseEventTask')
            ->label(__('Choose activity'))
            ->modalHeading(__('Several activities share this column'))
            ->modalDescription(__('Pick which activity you want to edit. Saving still updates only that activity.'))
            ->modalSubmitActionLabel(__('Edit selected'))
            ->schema(function (array $arguments): array {
                $ids = collect($arguments['taskIds'] ?? [])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter()
                    ->values();

                $churchId = app(CurrentChurch::class)->id() ?? auth()->user()?->church_id;

                /* The ids come from the browser: only list this church's tasks. */
                $options = EventTask::query()
                    ->whereIn('id', $ids)
                    ->whereHas('event', fn ($query) => $query->where('church_id', $churchId ?? 0))
                    ->with(['assignedUser', 'assignedTeam', 'template'])
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get()
                    ->mapWithKeys(function (EventTask $task): array {
                        $bits = array_filter([
                            $task->displayName(),
                            $task->assignedUser?->displayName(),
                            $task->assignedTeam?->name,
                        ]);

                        return [$task->id => implode(' — ', $bits) ?: ('#'.$task->id)];
                    })
                    ->all();

                return [
                    Select::make('taskId')
                        ->label(__('Activity'))
                        ->required()
                        ->native(false)
                        ->options($options),
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $taskId = (int) ($data['taskId'] ?? 0);
                $allowed = collect($arguments['taskIds'] ?? [])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                abort_unless(in_array($taskId, $allowed, true), 403);

                $this->focusCell = is_string($arguments['focusCell'] ?? null)
                    ? $arguments['focusCell']
                    : $this->focusCell;

                $this->mountAction('editEventTask', [
                    'taskId' => $taskId,
                    'focusCell' => $this->focusCell,
                ]);
            });
    }

    public function setStaffingView(string $view): void
    {
        $key = SpreadsheetViewKey::tryFrom($view) ?? SpreadsheetViewKey::AllStaffing;
        $this->staffingView = $key->value;
        $this->rememberPrefs(['view' => $this->staffingView]);
        $this->refreshSpreadsheet();
        $this->resetPage($this->getTablePaginationPageName());
    }

    public function setRange(string $range): void
    {
        $names = $this->tableFilters['events']['names'] ?? null;
        $wasAllTypes = $names === null || count(array_diff(array_keys($this->eventNameOptions()), $names)) === 0;
        $key = SpreadsheetRangeKey::tryFrom($range) ?? SpreadsheetRangeKey::NextSixWeeks;
        $this->range = $key->value;

        if ($key !== SpreadsheetRangeKey::Custom) {
            $this->customFrom = null;
            $this->customUntil = null;
            $this->tableFilters['custom_dates'] = ['from' => null, 'until' => null];
        }
        $this->rememberPrefs([
            'range' => $this->range,
            'from' => $this->customFrom,
            'until' => $this->customUntil,
        ]);
        $this->refreshSpreadsheet();
        if ($wasAllTypes) {
            $this->tableFilters['events']['names'] = array_keys($this->eventNameOptions());
        }

        $this->updateFilamentTableFilters();
    }

    public function selectEventType(?string $name = null): void
    {
        abort_unless($name === null || array_key_exists($name, $this->eventNameOptions()), 422);

        $this->tableFilters['events']['names'] = $name === null ? array_keys($this->eventNameOptions()) : [$name];
        $this->updatedTableFilters();
    }

    public function selectEventTypes(bool $all): void
    {
        $this->tableFilters['events']['names'] = $all ? array_keys($this->eventNameOptions()) : [];
        $this->updatedTableFilters();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int|string, array<string, mixed>>
     */
    protected function filteredRows(?string $search, array $filters): Collection
    {
        $matrix = $this->matrix ??= $this->buildMatrix();
        $records = $matrix['rows'];

        $records = match ($this->viewKey()) {
            SpreadsheetViewKey::NeedsAttention => $records->filter(fn (array $row): bool => (bool) $row['needs_attention']),
            SpreadsheetViewKey::MyTeamNeeds => $records->filter(fn (array $row): bool => (bool) $row['my_team_needs']),
            default => $records,
        };

        $names = $filters['events']['names'] ?? null;

        if (is_array($names)) {
            $records = $records->filter(fn (array $row): bool => in_array($row['event_name'], $names, true));
        }

        if (filled($search)) {
            $needle = Str::lower($search);
            $records = $records->filter(function (array $row) use ($needle): bool {
                foreach ($row as $key => $value) {
                    if (! is_string($value) || str_ends_with($key, '_state')) {
                        continue;
                    }

                    $text = Str::lower(html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8'));

                    if (str_contains($text, $needle)) {
                        return true;
                    }
                }

                return false;
            });
        }

        return $records;
    }

    public function refreshSpreadsheet(): void
    {
        $this->matrix = $this->buildMatrix();
        $this->flushCachedTableRecords();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRows(): array
    {
        $matrix = $this->matrix ??= $this->buildMatrix();
        $view = $this->viewKey();
        $rows = $this->filteredRows($this->tableSearch, $this->tableFilters ?? []);
        $visibleColumns = $this->getTable()->getVisibleColumns();

        $items = [];

        foreach ($rows as $row) {
            foreach ($matrix['staffing_columns'] as $column) {
                if (! array_key_exists($column['key'], $visibleColumns)) {
                    continue;
                }

                $taskIds = $row[$column['key'].'_task_ids'] ?? [];
                if ($taskIds === [] && empty($row[$column['key'].'_task_id'])) {
                    continue;
                }

                $needs = (bool) ($row[$column['key'].'_needs_attention'] ?? false);
                $conflict = (bool) ($row[$column['key'].'_conflict'] ?? false);
                $myTeam = (bool) ($row[$column['key'].'_my_team'] ?? false);

                if ($view === SpreadsheetViewKey::NeedsAttention && ! ($needs || $conflict)) {
                    continue;
                }

                if ($view === SpreadsheetViewKey::MyTeamNeeds && ! ($myTeam && ($needs || $conflict))) {
                    continue;
                }

                $stateValue = $row[$column['key'].'_state'] ?? null;

                $items[] = [
                    'event_id' => $row['event_id'],
                    'event_name' => $row['event_name'],
                    'event_date_label' => $row['event_date_label'],
                    'event_date' => $row['event_date'],
                    'event_week' => $row['event_week'],
                    'event_day_label' => $row['event_day_label'],
                    'event_calendar_label' => $row['event_calendar_label'],
                    'event_tone' => $row['event_tone'],
                    'event_time_label' => $row['event_time_label'] ?? null,
                    'column' => $column['name'],
                    'column_key' => $column['key'],
                    'html' => (string) ($row[$column['key']] ?? ''),
                    'vacant' => (bool) ($row[$column['key'].'_vacant'] ?? false),
                    'conflict' => $conflict,
                    'state' => is_string($stateValue) ? AssignmentState::tryFrom($stateValue) : null,
                    'task_id' => $row[$column['key'].'_task_id'] ?? null,
                    'task_ids' => $taskIds,
                    'focus' => $column['key'].'-'.$row['event_id'],
                    'event_url' => EventResource::getUrl('edit', [
                        'record' => $row['event_id'],
                    ]).'?return=tasks-by-event',
                ];
            }
        }

        return $items;
    }

    public function table(Table $table): Table
    {
        $matrix = $this->matrix ??= $this->buildMatrix();

        $identityColumns = [
            TextColumn::make('event_date_label')
                ->label(__('Date'))
                ->html()
                ->formatStateUsing(fn (array $record): HtmlString => new HtmlString(
                    '<time class="wp-ss-date" datetime="'.e($record['event_date']).'">'
                    .'<span class="wp-ss-date-main">'.e($record['event_calendar_label']).'</span>'
                    .'<span class="wp-ss-date-meta">'.e($record['event_day_label']).' · '.e($record['event_time_label']).'</span></time>'
                ))
                ->sortable()
                ->toggleable()
                ->extraHeaderAttributes(['class' => 'wp-ss-pin wp-ss-pin-date'])
                ->extraCellAttributes(['class' => 'wp-ss-pin wp-ss-pin-date']),
            TextColumn::make('event_week')
                ->label(__('Week'))
                ->toggleable()
                ->extraHeaderAttributes(['class' => 'wp-ss-pin wp-ss-pin-week'])
                ->extraCellAttributes(['class' => 'wp-ss-pin wp-ss-pin-week']),
            TextColumn::make('event_name')
                ->label(__('Event'))
                ->wrap()
                ->sortable()
                ->url(fn (array $record): string => EventResource::getUrl('edit', [
                    'record' => $record['event_id'],
                ]).'?return=tasks-by-event')
                ->toggleable()
                ->extraHeaderAttributes(['class' => 'wp-ss-pin wp-ss-pin-event'])
                ->extraCellAttributes(['class' => 'wp-ss-pin wp-ss-pin-event']),
        ];

        $staffingColumns = [];

        foreach ($matrix['staffing_columns'] as $column) {
            $staffingColumns[] = TextColumn::make($column['key'])
                ->label($column['name'])
                ->disabledClick()
                ->wrap()
                ->toggleable()
                ->html()
                ->formatStateUsing(function (?string $state, array $record) use ($column): HtmlString {
                    $stateValue = $record[$column['key'].'_state'] ?? null;
                    $taskIds = $record[$column['key'].'_task_ids'] ?? [];
                    if (! is_array($taskIds)) {
                        $taskIds = [];
                    }

                    return new HtmlString(view('filament.tables.spreadsheet-cell', [
                        'taskId' => $record[$column['key'].'_task_id'] ?? null,
                        'taskIds' => $taskIds,
                        'html' => (string) ($state ?? ''),
                        'vacant' => (bool) ($record[$column['key'].'_vacant'] ?? false),
                        'conflict' => (bool) ($record[$column['key'].'_conflict'] ?? false),
                        'state' => is_string($stateValue) ? AssignmentState::tryFrom($stateValue) : null,
                        'focusCell' => $column['key'].'-'.$record['event_id'],
                    ])->render());
                });
        }

        $extraColumns = [];

        foreach ($matrix['extra_columns'] as $column) {
            $extraColumns[] = TextColumn::make($column['key'])
                ->label($column['name'])
                ->wrap()
                ->toggleable()
                ->placeholder('—')
                ->extraCellAttributes(['class' => 'wp-ss-details'])
                ->color('gray');
        }

        $columns = [
            ...$identityColumns,
            ColumnGroup::make(__('People & teams'), $staffingColumns),
        ];

        if ($extraColumns !== []) {
            $columns[] = ColumnGroup::make(__('Program details'), $extraColumns);
        }

        $columns[] = ColumnGroup::make(__('Availability'), [
            TextColumn::make('unavailable')
                ->label(__('Unavailable'))
                ->extraCellAttributes(['class' => 'wp-ss-unavailable'])
                ->wrap()
                ->toggleable()
                ->placeholder('—')
                ->color('danger'),
        ]);

        return $table
            ->records(function (
                ?string $search,
                ?string $sortColumn,
                ?string $sortDirection,
                array $filters,
                int $page,
                int|string $recordsPerPage,
            ): LengthAwarePaginator {
                $records = $this->filteredRows($search, $filters);

                $sortColumn ??= 'event_date';
                $sortDirection ??= 'asc';

                $records = $records->sortBy(
                    function (array $row) use ($sortColumn): mixed {
                        return match ($sortColumn) {
                            'event_date_label', 'event_date' => $row['event_date'] ?? '',
                            default => Str::lower((string) ($row[$sortColumn] ?? '')),
                        };
                    },
                    SORT_REGULAR,
                    $sortDirection === 'desc',
                )->values();

                $records = static::annotateMonthRowBands($records);

                $total = $records->count();
                $perPage = $recordsPerPage === 'all'
                    ? max($total, 1)
                    : max(1, (int) $recordsPerPage);

                return new LengthAwarePaginator(
                    $records->forPage($page, $perPage)->keyBy('key'),
                    $total,
                    $perPage,
                    $page,
                );
            })
            ->recordClasses(function (array $record): array {
                $month = (int) ($record['wp_month_band'] ?? 0);
                $parity = (int) ($record['wp_row_parity'] ?? 0) === 0 ? 'even' : 'odd';

                return [
                    'wp-ss-month-'.$month,
                    'wp-ss-row-'.$parity,
                    'wp-ss-tone-'.($record['event_tone'] ?? 0),
                ];
            })
            ->columns($columns)
            ->filters([
                Filter::make('custom_dates')
                    ->label(__('Custom dates'))
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('From'))
                            ->default(fn (): ?string => $this->customFrom),
                        DatePicker::make('until')
                            ->label(__('Until'))
                            ->default(fn (): ?string => $this->customUntil),
                    ])
                    ->indicateUsing(function (array $data): array {
                        $bits = [];
                        if (filled($data['from'] ?? null)) {
                            $bits[] = __('From :date', ['date' => $data['from']]);
                        }
                        if (filled($data['until'] ?? null)) {
                            $bits[] = __('Until :date', ['date' => $data['until']]);
                        }

                        return $bits;
                    })
                    ->query(fn ($query) => $query),
                Filter::make('events')
                    ->schema([
                        Select::make('names')
                            ->multiple()
                            ->options(fn (): array => $this->eventNameOptions())
                            ->default(fn (): array => array_keys($this->eventNameOptions())),
                    ]),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(2)
            ->deferFilters(false)
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->hiddenFilterIndicators()
            ->defaultSort('event_date_label', 'asc')
            ->searchable()
            ->striped(false)
            ->reorderableColumns()
            ->deferColumnManager(false)
            ->persistColumnsInSession()
            ->paginated([25, 50, 100, 'all'])
            ->emptyStateHeading(__('No upcoming events'))
            ->emptyStateDescription(__('Events from today onward appear here once they have person/team tasks.'));
    }

    public function updatedTableFilters(): void
    {
        $from = $this->tableFilters['custom_dates']['from'] ?? null;
        $until = $this->tableFilters['custom_dates']['until'] ?? null;
        $datesChanged = $from !== $this->customFrom || $until !== $this->customUntil;
        $names = $this->tableFilters['events']['names'] ?? null;
        $wasAllTypes = $names === null || array_diff(array_keys($this->eventNameOptions()), $names) === [];
        $this->customFrom = filled($from) ? (string) $from : null;
        $this->customUntil = filled($until) ? (string) $until : null;

        if (filled($this->customFrom) || filled($this->customUntil)) {
            $this->range = SpreadsheetRangeKey::Custom->value;
        }

        $this->rememberPrefs([
            'range' => $this->range,
            'from' => $this->customFrom,
            'until' => $this->customUntil,
        ]);
        $this->refreshSpreadsheet();
        if ($datesChanged && $wasAllTypes) {
            $this->tableFilters['events']['names'] = array_keys($this->eventNameOptions());
        }

        $this->updateFilamentTableFilters();
    }

    /**
     * Alternate month bands (0/1) and within-month row parity for spreadsheet striping.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public static function annotateMonthRowBands(Collection $rows): Collection
    {
        $monthBand = 0;
        $prevMonthKey = null;
        $rowInMonth = 0;

        return $rows->values()->map(function (array $row) use (&$monthBand, &$prevMonthKey, &$rowInMonth): array {
            $monthKey = filled($row['event_date'] ?? null)
                ? substr((string) $row['event_date'], 0, 7)
                : '';

            if ($prevMonthKey !== null && $monthKey !== $prevMonthKey) {
                $monthBand = 1 - $monthBand;
                $rowInMonth = 0;
            }

            $prevMonthKey = $monthKey;
            $row['wp_month_band'] = $monthBand;
            $row['wp_row_parity'] = $rowInMonth % 2;
            $rowInMonth++;

            return $row;
        });
    }

    /**
     * Unique event names for the Event filter (one option per distinct name).
     *
     * @return array<string, string>
     */
    protected function eventNameOptions(): array
    {
        $matrix = $this->matrix ??= $this->buildMatrix();

        return $matrix['rows']
            ->pluck('event_name')
            ->map(fn (mixed $name): string => (string) $name)
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->mapWithKeys(fn (string $name): array => [$name => $name])
            ->all();
    }

    /**
     * @return array{staffing_columns: list<array{id: int, name: string, key: string}>, extra_columns: list<array{id: int, name: string, key: string}>, rows: Collection}
     */
    protected function buildMatrix(): array
    {
        $church = $this->church();

        abort_unless($church, 403);

        $range = $this->rangeKey();
        [$from, $until] = $range->bounds($this->customFrom, $this->customUntil);

        $teamIds = auth()->user() instanceof User
            ? auth()->user()->teams()->pluck('teams.id')->all()
            : [];

        return app(TaskSpreadsheetBuilder::class)->build($church, $from, $until, $teamIds);
    }

    protected function getTableQuery(): mixed
    {
        return null;
    }

    protected function church(): ?Church
    {
        return app(CurrentChurch::class)->get() ?? auth()->user()?->church;
    }

    protected function viewKey(): SpreadsheetViewKey
    {
        return SpreadsheetViewKey::tryFrom($this->staffingView) ?? SpreadsheetViewKey::AllStaffing;
    }

    protected function rangeKey(): SpreadsheetRangeKey
    {
        return SpreadsheetRangeKey::tryFrom($this->range) ?? SpreadsheetRangeKey::NextSixWeeks;
    }

    /**
     * @param  array<string, mixed>  $prefs
     */
    protected function rememberPrefs(array $prefs): void
    {
        $user = auth()->user();
        $church = $this->church();

        if (! $user instanceof User || $church === null) {
            return;
        }

        app(TaskSpreadsheetPreferences::class)->remember($user, $church, $prefs);
    }

    protected function focusCellScript(): string
    {
        if (! filled($this->focusCell)) {
            return '';
        }

        $id = e($this->focusCell);

        return <<<JS
            requestAnimationFrame(() => {
                const el = document.querySelector('[data-wp-cell="{$id}"]');
                el?.focus({ preventScroll: false });
            });
            JS;
    }

    public function rememberFocusCell(?string $focusCell): void
    {
        $this->focusCell = $focusCell;
    }

    public function sendTaskMessageAction(): Action
    {
        return SendTaskMessageAction::make();
    }
}
