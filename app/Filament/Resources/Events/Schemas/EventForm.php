<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Models\Event;
use App\Services\EventCopier;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Exists;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('service_template_id')
                    ->label(__('Service template'))
                    ->placeholder(__('No template — create a blank event'))
                    ->helperText(__('Choose a template to fill in the name and time and copy its service order. You can change the details below.'))
                    ->options(fn (): array => auth()->user()?->can('useTemplates', Event::class)
                        ? Event::templates()->where('church_id', auth()->user()->church_id)->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->searchable()
                    ->preload()
                    ->live()
                    ->exists('events', 'id', modifyRuleUsing: fn (Exists $rule): Exists => $rule
                        ->where('church_id', auth()->user()?->church_id)
                        ->where('is_template', true))
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if (blank($state) || ! auth()->user()?->can('useTemplates', Event::class)) {
                            return;
                        }

                        $template = Event::templates()->where('church_id', auth()->user()->church_id)->find($state);

                        if ($template === null) {
                            return;
                        }

                        $set('name', $template->name);
                        $set('description', $template->description);
                        $set('start_time', $template->starts_at->format('H:i'));
                    })
                    ->visible(fn (string $operation): bool => $operation === 'create' && (auth()->user()?->can('useTemplates', Event::class) ?? false))
                    ->columnSpanFull(),
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(100)
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->label(__('Description'))
                    ->rows(3)
                    ->columnSpanFull(),
                DateTimePicker::make('starts_at')
                    ->label(fn (string $operation): string => $operation === 'create' ? __('Start date') : __('Starts'))
                    ->required()
                    ->time(fn (string $operation): bool => $operation !== 'create')
                    ->default(fn () => today()->setTime(11, 0))
                    ->defaultFocusedDate(fn () => today())
                    ->closeOnDateSelection(fn (string $operation): bool => $operation === 'create')
                    ->extraAttributes(['class' => 'wp-event-start-calendar'])
                    ->extraAlpineAttributes([
                        'x-on:wheel' => <<<'JS'
                            if (!isOpen() || !$refs.panel.contains($event.target) || $event.ctrlKey || !$event.deltaY) return;
                            $event.preventDefault();
                            if (Date.now() - Number($el.dataset.lastMonthScroll || 0) < 300) return;
                            $el.dataset.lastMonthScroll = Date.now();
                            focusedDate = focusedDate.add($event.deltaY > 0 ? 1 : -1, 'month');
                            JS,
                    ])
                    ->seconds(false)
                    ->minutesStep(15)
                    ->native(false),
                ViewField::make('start_time')
                    ->label(__('Start time'))
                    ->view('filament.events.start-time-picker')
                    ->default('11:00')
                    ->rules(['date_format:H:i'])
                    ->required()
                    ->visibleOn('create'),
                TextInput::make('weeks')
                    ->label(__('Number of weeks to copy to'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(EventCopier::MAX_WEEKS)
                    ->default(1)
                    ->required()
                    ->helperText(__('1 = one copy at the dates above. 3 = three copies, each one week later.'))
                    ->visible(fn (string $operation, Get $get): bool => static::createsFromTemplate($operation, $get))
                    ->columnSpanFull(),
                Toggle::make('copy_people_and_teams')
                    ->label(__('Copy persons and teams'))
                    ->default(true)
                    ->helperText(__('Keep assignees and teams on each task. Off = blank assignments.'))
                    ->visible(fn (string $operation, Get $get): bool => static::createsFromTemplate($operation, $get)),
                Toggle::make('copy_songs')
                    ->label(__('Copy songs'))
                    ->default(true)
                    ->helperText(__('Keep song titles on each task. Off = clear songs.'))
                    ->visible(fn (string $operation, Get $get): bool => static::createsFromTemplate($operation, $get)),
            ]);
    }

    /**
     * The copy options only apply when a new service is made from a template.
     */
    protected static function createsFromTemplate(string $operation, Get $get): bool
    {
        return $operation === 'create' && filled($get('service_template_id'));
    }
}
