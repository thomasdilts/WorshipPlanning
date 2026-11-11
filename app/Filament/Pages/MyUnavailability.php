<?php

namespace App\Filament\Pages;

use App\Core\Modules\ChurchModuleManager;
use App\Filament\Tables\DatePeriodFilter;
use App\Models\Unavailability;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\PermissionRegistrar;

class MyUnavailability extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public function getTitle(): string|Htmlable
    {
        return __('My unavailability');
    }

    protected static ?string $slug = 'my-unavailability';

    protected string $view = 'filament-panels::pages.page';

    /** Own unavailability lives under the profile menu, not the main nav. */
    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->church_id === null || $user->church === null) {
            return false;
        }

        if (! app(ChurchModuleManager::class)->isEnabled($user->church, 'unavailability')) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('unavailability.manage_own') || $user->can('unavailability.manage');
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
            ->query($this->ownQuery())
            ->defaultSort('starts_on', 'asc')
            ->filters([
                DatePeriodFilter::make('ends_on'),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(1)
            ->deferFilters(false)
            ->hiddenFilterIndicators()
            ->recordClasses(fn (Unavailability $record): ?string => $record->ends_on->lt(today()) ? 'wp-unavailability-past' : null)
            ->columns([
                TextColumn::make('period_status')
                    ->label(__('Status'))
                    ->state(fn (Unavailability $record): string => match (true) {
                        $record->ends_on->lt(today()) => __('Past'),
                        $record->starts_on->lte(today()) => __('Current'),
                        default => __('Upcoming'),
                    })
                    ->badge()
                    ->color(fn (Unavailability $record): string => $record->ends_on->lt(today()) ? 'gray' : ($record->starts_on->lte(today()) ? 'warning' : 'primary')),
                TextColumn::make('starts_on')
                    ->label(__('Starts'))
                    ->date()
                    ->sortable(),
                TextColumn::make('ends_on')
                    ->label(__('Ends'))
                    ->date()
                    ->sortable(),
                TextColumn::make('note')
                    ->label(__('Note'))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->headerActions([
                $this->makeCreateAction(),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema($this->formSchema())
                    ->mutateDataUsing(function (array $data): array {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);
                        $data['user_id'] = $user->id;
                        $data['team_id'] = null;

                        return $data;
                    }),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No unavailable dates in this view'))
            ->emptyStateDescription(__('Add the dates you will be away so planners can avoid scheduling you.'))
            ->emptyStateActions([
                $this->makeCreateAction(),
            ]);
    }

    protected function makeCreateAction(): CreateAction
    {
        return CreateAction::make()
            ->model(Unavailability::class)
            ->label(__('Add unavailability'))
            ->modalHeading(__('Add unavailability'))
            ->schema($this->formSchema())
            ->mutateDataUsing(function (array $data): array {
                $user = auth()->user();
                abort_unless($user instanceof User && $user->church_id, 403);

                $data['church_id'] = $user->church_id;
                $data['user_id'] = $user->id;
                $data['team_id'] = null;

                return $data;
            })
            ->successNotification(
                Notification::make()
                    ->success()
                    ->title(__('Unavailability added'))
            );
    }

    /**
     * @return list<Component>
     */
    protected function formSchema(): array
    {
        return [
            DatePicker::make('starts_on')
                ->label(__('Starts'))
                ->required()
                ->native(false)
                ->live(onBlur: true),
            DatePicker::make('ends_on')
                ->label(__('Ends'))
                ->required()
                ->native(false)
                ->afterOrEqual('starts_on'),
            TextInput::make('note')
                ->label(__('Note'))
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }

    protected function ownQuery(): Builder
    {
        $user = auth()->user();

        return Unavailability::query()
            ->personal()
            ->where('user_id', $user?->id ?? 0)
            ->where('church_id', $user?->church_id ?? 0);
    }
}
