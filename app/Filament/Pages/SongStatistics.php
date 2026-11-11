<?php

namespace App\Filament\Pages;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Songs\SongResource;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\SongStatistics\Services\SongUsageStatistics;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

class SongStatistics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Songs');
    }

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('Statistics');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Song statistics');
    }

    protected static ?string $slug = 'song-statistics';

    protected string $view = 'filament-panels::pages.page';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->church_id === null || $user->church === null) {
            return false;
        }

        if (! app(ChurchModuleManager::class)->isEnabled($user->church, 'song_statistics')) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('song_statistics.view');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (
                ?string $search,
                ?string $sortColumn,
                ?string $sortDirection,
                array $filters,
                array $columnSearches,
                int $page,
                int|string $recordsPerPage,
            ): LengthAwarePaginator {
                $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;

                if ($church === null) {
                    $perPage = is_numeric($recordsPerPage) ? (int) $recordsPerPage : 25;

                    return new LengthAwarePaginator([], 0, $perPage, $page);
                }

                $records = app(SongUsageStatistics::class)->forChurch($church);
                $records = $this->applyGridFilters($records, $filters, $search, $columnSearches);
                $records = $this->applyGridSort($records, $sortColumn, $sortDirection);

                $total = $records->count();
                $perPage = $recordsPerPage === 'all'
                    ? max($total, 1)
                    : max(1, (int) $recordsPerPage);

                $pageItems = $records
                    ->forPage($page, $perPage)
                    ->keyBy('key');

                return new LengthAwarePaginator(
                    $pageItems,
                    $total,
                    $perPage,
                    $page,
                );
            })
            ->columns([
                TextColumn::make('title')
                    ->label(__('Song'))
                    ->sortable()
                    ->searchable(isIndividual: true)
                    ->toggleable(),
                TextColumn::make('author')
                    ->label(__('Author'))
                    ->placeholder('—')
                    ->sortable()
                    ->searchable(isIndividual: true)
                    ->toggleable(),
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'catalog' ? 'Song list' : 'Manual')
                    ->color(fn (string $state): string => $state === 'catalog' ? 'success' : 'warning')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('usage_count')
                    ->label(__('Times used'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('event_count')
                    ->label(__('Services'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('first_used_at')
                    ->label(__('First used'))
                    ->date()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_used_at')
                    ->label(__('Last used'))
                    ->date()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('title')
                    ->schema([
                        TextInput::make('value')
                            ->label(__('Song contains'))
                            ->placeholder(__('Title…')),
                    ]),
                Filter::make('author')
                    ->schema([
                        TextInput::make('value')
                            ->label(__('Author contains'))
                            ->placeholder(__('Author…')),
                    ]),
                SelectFilter::make('source')
                    ->label(__('Source'))
                    ->options([
                        'catalog' => 'Song list',
                        'manual' => 'Manual entry',
                    ]),
                Filter::make('usage_count')
                    ->schema([
                        TextInput::make('min')
                            ->label(__('Times used ≥'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('max')
                            ->label(__('Times used ≤'))
                            ->numeric()
                            ->minValue(0),
                    ])
                    ->columns(2),
                Filter::make('event_count')
                    ->schema([
                        TextInput::make('min')
                            ->label(__('Services ≥'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('max')
                            ->label(__('Services ≤'))
                            ->numeric()
                            ->minValue(0),
                    ])
                    ->columns(2),
                Filter::make('first_used_at')
                    ->schema([
                        DatePicker::make('from')->label(__('First used from')),
                        DatePicker::make('until')->label(__('First used until')),
                    ])
                    ->columns(2),
                Filter::make('last_used_at')
                    ->schema([
                        DatePicker::make('from')->label(__('Last used from')),
                        DatePicker::make('until')->label(__('Last used until')),
                    ])
                    ->columns(2),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(3)
            ->deferFilters(false)
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->persistSortInSession()
            ->reorderableColumns()
            ->deferColumnManager(false)
            ->persistColumnsInSession()
            ->recordActions([
                Action::make('openCatalog')
                    ->label(__('Open in song list'))
                    ->icon('heroicon-o-musical-note')
                    ->visible(fn (array $record): bool => ($record['source'] ?? null) === 'catalog'
                        && filled($record['song_id'] ?? null)
                        && SongResource::canViewAny())
                    ->url(fn (array $record): string => SongResource::getUrl('edit', [
                        'record' => $record['song_id'],
                    ])),
            ])
            ->defaultSort('usage_count', 'desc')
            ->searchable()
            ->striped()
            ->paginated([25, 50, 100, 'all'])
            ->emptyStateHeading(__('No songs used yet'))
            ->emptyStateDescription(__('Songs appear here when service-order tasks have a catalog song or a manual title.'));
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $records
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $columnSearches
     * @return Collection<int, array<string, mixed>>
     */
    protected function applyGridFilters(
        Collection $records,
        array $filters,
        ?string $search,
        array $columnSearches,
    ): Collection {
        if (filled($title = $filters['title']['value'] ?? null)) {
            $needle = Str::lower((string) $title);
            $records = $records->filter(
                fn (array $record): bool => str_contains(Str::lower($record['title']), $needle)
            );
        }

        if (filled($author = $filters['author']['value'] ?? null)) {
            $needle = Str::lower((string) $author);
            $records = $records->filter(
                fn (array $record): bool => str_contains(Str::lower((string) ($record['author'] ?? '')), $needle)
            );
        }

        if (filled($source = $filters['source']['value'] ?? null)) {
            $records = $records->where('source', $source);
        }

        $records = $this->applyNumericRangeFilter($records, 'usage_count', $filters['usage_count'] ?? []);
        $records = $this->applyNumericRangeFilter($records, 'event_count', $filters['event_count'] ?? []);
        $records = $this->applyDateRangeFilter($records, 'first_used_at', $filters['first_used_at'] ?? []);
        $records = $this->applyDateRangeFilter($records, 'last_used_at', $filters['last_used_at'] ?? []);

        if (filled($columnTitle = $columnSearches['title'] ?? null)) {
            $needle = Str::lower((string) $columnTitle);
            $records = $records->filter(
                fn (array $record): bool => str_contains(Str::lower($record['title']), $needle)
            );
        }

        if (filled($columnAuthor = $columnSearches['author'] ?? null)) {
            $needle = Str::lower((string) $columnAuthor);
            $records = $records->filter(
                fn (array $record): bool => str_contains(Str::lower((string) ($record['author'] ?? '')), $needle)
            );
        }

        if (filled($search)) {
            $needle = Str::lower($search);
            $records = $records->filter(function (array $record) use ($needle): bool {
                return str_contains(Str::lower($record['title']), $needle)
                    || str_contains(Str::lower((string) ($record['author'] ?? '')), $needle)
                    || str_contains(Str::lower($record['source']), $needle)
                    || str_contains((string) $record['usage_count'], $needle)
                    || str_contains((string) $record['event_count'], $needle);
            });
        }

        return $records->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return Collection<int, array<string, mixed>>
     */
    protected function applyGridSort(
        Collection $records,
        ?string $sortColumn,
        ?string $sortDirection,
    ): Collection {
        $sortColumn ??= 'usage_count';
        $sortDirection ??= 'desc';

        $numeric = in_array($sortColumn, ['usage_count', 'event_count'], true);
        $dates = in_array($sortColumn, ['first_used_at', 'last_used_at'], true);

        return $records->sortBy(
            function (array $record) use ($sortColumn, $numeric, $dates): mixed {
                $value = $record[$sortColumn] ?? null;

                if ($numeric) {
                    return (int) $value;
                }

                if ($dates) {
                    return $value ? Carbon::parse((string) $value)->timestamp : 0;
                }

                return Str::lower((string) $value);
            },
            SORT_REGULAR,
            $sortDirection === 'desc',
        )->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @param  array<string, mixed>  $range
     * @return Collection<int, array<string, mixed>>
     */
    protected function applyNumericRangeFilter(Collection $records, string $field, array $range): Collection
    {
        if (filled($min = $range['min'] ?? null)) {
            $records = $records->filter(fn (array $record): bool => (int) $record[$field] >= (int) $min);
        }

        if (filled($max = $range['max'] ?? null)) {
            $records = $records->filter(fn (array $record): bool => (int) $record[$field] <= (int) $max);
        }

        return $records;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @param  array<string, mixed>  $range
     * @return Collection<int, array<string, mixed>>
     */
    protected function applyDateRangeFilter(Collection $records, string $field, array $range): Collection
    {
        if (filled($from = $range['from'] ?? null)) {
            $fromDate = Carbon::parse((string) $from)->startOfDay();
            $records = $records->filter(function (array $record) use ($field, $fromDate): bool {
                if (blank($record[$field] ?? null)) {
                    return false;
                }

                return Carbon::parse((string) $record[$field])->gte($fromDate);
            });
        }

        if (filled($until = $range['until'] ?? null)) {
            $untilDate = Carbon::parse((string) $until)->endOfDay();
            $records = $records->filter(function (array $record) use ($field, $untilDate): bool {
                if (blank($record[$field] ?? null)) {
                    return false;
                }

                return Carbon::parse((string) $record[$field])->lte($untilDate);
            });
        }

        return $records;
    }

    protected function getTableQuery(): mixed
    {
        return null;
    }
}
