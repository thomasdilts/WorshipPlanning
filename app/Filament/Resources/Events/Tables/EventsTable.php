<?php

namespace App\Filament\Resources\Events\Tables;

use App\Filament\Resources\Events\Actions\CopyEventAction;
use App\Filament\Resources\Events\Actions\SaveAsTemplateAction;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use App\Services\EventTaskRowSummary;
use App\Support\DateTimeFormats;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
            ->recordUrl(fn (Event $record): string => EventResource::getUrl(
                EventResource::canEdit($record) ? 'edit' : 'view',
                ['record' => $record],
            ))
            ->defaultSort('starts_at')
            ->defaultPaginationPageOption(25)
            ->deferFilters(false)
            ->hiddenFilterIndicators()
            ->deferColumnManager(false)
            ->recordClasses(fn (Event $record, ListEvents $livewire): array => [
                'wp-ss-tone-'.((int) array_search($record->name, array_values($livewire->eventNameOptions()), true) % 3),
            ])
            ->emptyStateHeading(__('No upcoming services'))
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
                        .'<span class="wp-ss-date-main">'.e($record->starts_at?->translatedFormat(DateTimeFormats::date())).'</span>'
                        .'<span class="wp-ss-date-meta">'.e($record->starts_at?->translatedFormat('l')).' · '.e(__('Week :number', ['number' => $record->starts_at?->isoWeek()])).'</span></time>'
                    ))
                    ->sortable(),
                TextColumn::make('event_time')
                    ->label(__('Time'))
                    ->state(fn (Event $record): ?string => DateTimeFormats::formatTime($record->starts_at)),
                TextColumn::make('name')
                    ->label(__('Service'))
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
                            ->label(__('Show services from'))
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
                CopyEventAction::make(),
                SaveAsTemplateAction::make(),
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
