<?php

namespace App\Filament\Resources\Events\Actions;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Services\EventCopier;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Saves a copy of a service as a service template: every task, person, team and song, but no messages.
 */
class SaveAsTemplateAction
{
    public static function make(): Action
    {
        return Action::make('saveAsTemplate')
            ->label(__('Save as service template'))
            ->icon(Heroicon::OutlinedBookmark)
            ->iconButton()
            ->color('gray')
            ->tooltip(__('Save as service template'))
            ->authorize(fn (Event $record): bool => auth()->user()?->can('saveAsTemplate', $record) ?? false)
            ->modalHeading(fn (Event $record): string => __('Save “:name” as a service template', ['name' => $record->name]))
            ->modalDescription(__('The template keeps every task, person, team and song of this service. Messages are not copied.'))
            ->modalSubmitActionLabel(__('Save template'))
            ->form(fn (Event $record): array => [
                TextInput::make('name')
                    ->label(__('Template name'))
                    ->required()
                    ->maxLength(255)
                    ->default($record->name),
                Textarea::make('description')
                    ->label(__('Description'))
                    ->rows(3)
                    ->default($record->description),
            ])
            ->action(function (Event $record, array $data, Action $action): void {
                app(EventCopier::class)->copy($record, [
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'starts_at' => $record->starts_at,
                    'copy_people_and_teams' => true,
                    'copy_songs' => true,
                    'as_template' => true,
                ]);

                Notification::make()
                    ->title(__('Service template saved'))
                    ->success()
                    ->send();

                $action->redirect(EventResource::getUrl('templates'));
            });
    }
}
