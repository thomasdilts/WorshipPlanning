<?php

namespace App\Filament\Resources\AutomaticMessages\Tables;

use App\Filament\Resources\AutomaticMessages\Schemas\AutomaticMessageForm;
use App\Models\AutomaticMessage;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class AutomaticMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('template')->withCount('sentForEvents'))
            ->defaultSort('hours_before', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('hours_before')
                    ->label(__('When'))
                    ->formatStateUsing(fn (int $state): string => __(':time before the start', ['time' => AutomaticMessageForm::duration($state)]))
                    ->sortable(),
                TextColumn::make('template.name')
                    ->label(__('Message template'))
                    ->placeholder(__('Template deleted — never sent'))
                    ->color(fn (AutomaticMessage $record): ?string => $record->template === null ? 'danger' : null),
                TextColumn::make('sent_for_events_count')
                    ->label(__('Sent for services'))
                    ->numeric(),
                ToggleColumn::make('is_active')->label(__('Active')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No automatic messages yet'))
            ->emptyStateDescription(__('Add one to remind everyone on a service, for example three days before it starts.'));
    }
}
