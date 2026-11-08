<?php

namespace App\Filament\Resources\Events\Tables;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use App\Services\EventCopier;
use App\Services\EventTaskRowSummary;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;

use function Filament\Support\generate_icon_html;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->header(fn () => view('filament.events.services-toolbar'))
            ->defaultSort('starts_at')
            ->deferFilters(false)
            ->hiddenFilterIndicators()
            ->deferColumnManager(false)
            ->recordClasses(fn (Event $record, ListEvents $livewire): array => [
                'wp-ss-tone-'.((int) array_search($record->name, array_values($livewire->eventNameOptions()), true) % 3),
            ])
            ->emptyStateHeading(__('No upcoming events'))
            ->emptyStateDescription(__('Create a service to start building its order of worship.'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'church',
                'tasks.template',
                'tasks.assignedUser',
                'tasks.assignedTeam',
                'tasks.sharedFiles',
                'tasks.pictures',
            ]))
            ->columns([
                TextColumn::make('starts_at')
                    ->label(__('Date'))
                    ->html()
                    ->formatStateUsing(fn (Event $record): HtmlString => new HtmlString(
                        '<time class="wp-ss-date" datetime="'.e($record->starts_at?->toDateString()).'">'
                        .'<span class="wp-ss-date-main">'.e($record->starts_at?->translatedFormat('j M Y')).'</span>'
                        .'<span class="wp-ss-date-meta">'.e($record->starts_at?->translatedFormat('l')).' · '.e(__('Week :number', ['number' => $record->starts_at?->isoWeek()])).'</span></time>'
                    ))
                    ->sortable(),
                TextColumn::make('event_time')
                    ->label(__('Time'))
                    ->state(fn (Event $record): ?string => $record->starts_at?->format('H:i')),
                TextColumn::make('name')
                    ->label(__('Event'))
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->limit(60)
                    ->searchable()
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status_icons')
                    ->label('')
                    ->state(fn (Event $record): string => '1')
                    ->html()
                    ->formatStateUsing(function (Event $record): HtmlString {
                        $flags = app(EventTaskRowSummary::class)->eventProblemFlags($record);

                        $person = generate_icon_html(
                            Heroicon::OutlinedUser,
                            attributes: new ComponentAttributeBag([
                                'class' => $flags['staffing'] ? 'wp-event-status-bad' : 'wp-event-status-ok',
                                'title' => $flags['staffing']
                                    ? __('Person or team problems')
                                    : __('Person and team OK'),
                            ]),
                            size: IconSize::Medium,
                        );

                        $list = generate_icon_html(
                            Heroicon::OutlinedListBullet,
                            attributes: new ComponentAttributeBag([
                                'class' => $flags['other'] ? 'wp-event-status-bad' : 'wp-event-status-ok',
                                'title' => $flags['other']
                                    ? __('Other task problems')
                                    : __('Other task fields OK'),
                            ]),
                            size: IconSize::Medium,
                        );

                        return new HtmlString(
                            '<span class="wp-event-status-icons">'
                            .($person?->toHtml() ?? '')
                            .($list?->toHtml() ?? '')
                            .'</span>'
                        );
                    }),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('from_date')
                    ->label(__('From date'))
                    ->default([
                        'date' => now()->toDateString(),
                    ])
                    ->schema([
                        DatePicker::make('date')
                            ->label(__('Show events from'))
                            ->displayFormat('M j, Y')
                            ->default(fn (): string => now()->toDateString()),
                        DatePicker::make('until')
                            ->label(__('Until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (filled($data['date'] ?? null)) {
                            $query->whereDate('starts_at', '>=', Carbon::parse($data['date'])->toDateString());
                        }

                        if (filled($data['until'] ?? null)) {
                            $query->whereDate('starts_at', '<=', Carbon::parse($data['until'])->toDateString());
                        }

                        return $query;
                    }),
                Filter::make('events')
                    ->schema([
                        Select::make('names')
                            ->multiple()
                            ->options(fn (ListEvents $livewire): array => $livewire->eventNameOptions())
                            ->default(fn (ListEvents $livewire): array => array_keys($livewire->eventNameOptions())),
                    ])
                    ->query(function (Builder $query, array $data, ListEvents $livewire): Builder {
                        $names = $data['names'] ?? null;

                        return (! $livewire->allEventTypes && is_array($names)) ? $query->whereIn('name', $names) : $query;
                    }),
            ])
            ->recordActions([
                Action::make('copy')
                    ->label(__('Copy'))
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->iconButton()
                    ->color('gray')
                    ->tooltip(__('Copy event'))
                    ->authorize(fn (Event $record): bool => auth()->user()?->can('create', Event::class) ?? false)
                    ->modalHeading(fn (Event $record): string => __('Copy “:name”', ['name' => $record->name]))
                    ->modalSubmitActionLabel(__('Copy event'))
                    ->form(fn (Event $record): array => [
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255)
                            ->default($record->name),
                        Textarea::make('description')
                            ->label(__('Description'))
                            ->rows(3)
                            ->default($record->description),
                        DateTimePicker::make('starts_at')
                            ->label(__('New start'))
                            ->required()
                            ->seconds(false)
                            ->minutesStep(15)
                            ->native(false)
                            ->default($record->starts_at?->copy()->addWeek()),
                        TextInput::make('weeks')
                            ->label(__('Number of weeks to copy to'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->required()
                            ->helperText(__('1 = one copy at the dates above. 3 = three copies, each one week later.')),
                        Toggle::make('copy_people_and_teams')
                            ->label(__('Copy persons and teams'))
                            ->default(false)
                            ->helperText(__('Keep assignees and teams on each task. Off = blank assignments.')),
                        Toggle::make('copy_songs')
                            ->label(__('Copy songs'))
                            ->default(false)
                            ->helperText(__('Keep song titles on each task. Off = clear songs.')),
                    ])
                    ->action(function (Event $record, array $data, Action $action): void {
                        $copies = app(EventCopier::class)->copy($record, [
                            'name' => $data['name'],
                            'description' => $data['description'] ?? null,
                            'starts_at' => $data['starts_at'],
                            'weeks' => (int) ($data['weeks'] ?? 1),
                            'copy_people_and_teams' => (bool) ($data['copy_people_and_teams'] ?? false),
                            'copy_songs' => (bool) ($data['copy_songs'] ?? false),
                        ]);

                        $count = $copies->count();

                        Notification::make()
                            ->title($count === 1 ? __('Event copied') : __(':count events copied', ['count' => $count]))
                            ->success()
                            ->send();

                        $action->redirect(EventResource::getUrl('edit', [
                            'record' => $copies->first(),
                        ]));
                    }),
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
