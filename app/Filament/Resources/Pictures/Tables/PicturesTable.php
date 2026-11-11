<?php

namespace App\Filament\Resources\Pictures\Tables;

use App\Filament\Resources\Pictures\PictureResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PicturesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->persistSearchInSession()
            ->emptyStateHeading(__('No pictures yet'))
            ->emptyStateDescription(__('Upload images here, then attach them on a service task.'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('Add picture'))
                    ->url(PictureResource::getUrl('create'))
                    ->visible(fn (): bool => PictureResource::canCreate()),
            ])
            ->columns([
                ImageColumn::make('path')
                    ->label(__('Preview'))
                    ->disk('public')
                    ->square()
                    ->height(48)
                    ->defaultImageUrl(fn (): string => 'data:image/svg+xml,'.rawurlencode(
                        '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"><rect width="48" height="48" fill="#e2e8f0"/><text x="24" y="28" text-anchor="middle" fill="#64748b" font-size="10">?</text></svg>'
                    )),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('description')
                    ->limit(40)
                    ->toggleable()
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('event_tasks_count')
                    ->counts('eventTasks')
                    ->label(__('Used'))
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
