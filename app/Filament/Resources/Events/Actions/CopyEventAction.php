<?php

namespace App\Filament\Resources\Events\Actions;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Services\EventCopier;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Copies a service, or creates services from a service template, into new services.
 */
class CopyEventAction
{
    public static function make(): Action
    {
        return Action::make('copy')
            ->label(__('Copy'))
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->iconButton()
            ->color('gray')
            ->tooltip(fn (Event $record): string => $record->is_template ? __('Create a service from this template') : __('Copy event'))
            ->authorize(fn (Event $record): bool => auth()->user()?->can($record->is_template ? 'useTemplates' : 'create', Event::class) ?? false)
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
                    ->default(static::defaultStart($record)),
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
                    ->default($record->is_template)
                    ->helperText(__('Keep assignees and teams on each task. Off = blank assignments.')),
                Toggle::make('copy_songs')
                    ->label(__('Copy songs'))
                    ->default($record->is_template)
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
            });
    }

    /**
     * A service is copied one week on; a template to its next weekday and time from today.
     */
    protected static function defaultStart(Event $record): ?Carbon
    {
        if ($record->starts_at === null) {
            return null;
        }

        if (! $record->is_template) {
            return $record->starts_at->copy()->addWeek();
        }

        $next = now()->startOfDay()->setTimeFrom($record->starts_at);

        while ($next->dayOfWeek !== $record->starts_at->dayOfWeek || $next->isPast()) {
            $next->addDay();
        }

        return $next;
    }
}
