<?php

namespace App\Filament\Resources\Songs\Tables;

use App\Filament\Resources\Songs\SongResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SongsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->persistSearchInSession()
            ->emptyStateHeading(__('No songs yet'))
            ->emptyStateDescription(__('Add a song for the catalog, or import a CSV from More.'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('Add song'))
                    ->url(SongResource::getUrl('create'))
                    ->visible(fn (): bool => SongResource::canCreate()),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record): ?string => filled($record->name2) ? (string) $record->name2 : null)
                    ->wrap(),
                TextColumn::make('author')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('description')
                    ->label(__('Lyrics'))
                    ->limit(48)
                    ->toggleable()
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('event_tasks_count')
                    ->label(__('Used'))
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('last_used_at')
                    ->label(__('Last used'))
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('used_recently')
                    ->label(__('Used in the last 90 days'))
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'eventTasks',
                        fn (Builder $tasks): Builder => $tasks->where('updated_at', '>=', now()->subDays(90)),
                    )),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('Open')),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
