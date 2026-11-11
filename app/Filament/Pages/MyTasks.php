<?php

namespace App\Filament\Pages;

use App\Filament\Tables\AssignedTasksTable;
use App\Filament\Tables\DatePeriodFilter;
use App\Models\EventTask;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class MyTasks extends Page implements HasTable
{
    use InteractsWithTable;

    /** @var array<string, string> */
    protected array $extraBodyAttributes = [
        'class' => 'wp-my-tasks',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Planning');
    }

    protected static ?int $navigationSort = 0;

    public static function getNavigationLabel(): string
    {
        return __('My schedule');
    }

    public function getTitle(): string|Htmlable
    {
        return __('My schedule');
    }

    public function getHeading(): string|Htmlable
    {
        $name = auth()->user()?->displayName() ?? '';

        return new HtmlString(
            '<span class="wp-page-greeting">'
            .e(__('Schedule for :name', ['name' => $name]))
            .'</span>'
        );
    }

    public function getSubheading(): string|Htmlable|null
    {
        return now()->timezone(config('app.timezone'))->translatedFormat('l, j F Y');
    }

    protected static ?string $slug = 'my-tasks';

    protected string $view = 'filament-panels::pages.page';

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadCalendar')
                ->label(__('Download calendar'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->url(route('schedule.ics'))
                ->visible(fn (): bool => auth()->user()?->church_id !== null && auth()->user()?->can('events.view'))
                ->color('gray'),
        ];
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
        return AssignedTasksTable::configure(
            $table
                ->query($this->tasksQuery())
                ->emptyStateHeading(__('Nothing on your list yet'))
                ->emptyStateDescription(__('When a planner assigns you to a line in the service order, it appears here with the service date.')),
            ['schedule' => true],
        )
            ->filters([DatePeriodFilter::make('starts_at', 'event')], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(1)
            ->deferFilters(false)
            ->hiddenFilterIndicators()
            ->emptyStateDescription(__('No assignments in this view.'));
    }

    protected function tasksQuery(): Builder
    {
        $user = auth()->user();

        $ledTeamIds = $user?->ledTeams()->pluck('teams.id') ?? collect();

        return EventTask::query()
            ->with(['event', 'template', 'assignedTeam', 'responses'])
            ->where(function (Builder $query) use ($user, $ledTeamIds): void {
                $query->where('assigned_user_id', $user?->id ?? 0);

                if ($ledTeamIds->isNotEmpty()) {
                    $query->orWhereIn('assigned_team_id', $ledTeamIds);
                }
            })
            ->whereHas('event', function (Builder $query) use ($user): void {
                $query->where('church_id', $user?->church_id ?? 0);
            });
    }
}
