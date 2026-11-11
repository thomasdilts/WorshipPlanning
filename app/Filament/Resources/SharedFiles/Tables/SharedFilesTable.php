<?php

namespace App\Filament\Resources\SharedFiles\Tables;

use App\Filament\Resources\SharedFiles\SharedFileResource;
use App\Models\SharedFile;
use App\Support\UserAvatar;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SharedFilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->persistSearchInSession()
            ->emptyStateHeading(__('No files yet'))
            ->emptyStateDescription(__('Upload a file for the church or a team, then attach it on a service task.'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('Add file'))
                    ->url(SharedFileResource::getUrl('create'))
                    ->visible(fn (): bool => SharedFileResource::canCreate()),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('original_name')
                    ->label(__('Filename'))
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('size')
                    ->label(__('Size'))
                    ->state(fn (SharedFile $record): string => $record->humanSize())
                    ->toggleable(),
                TextColumn::make('teams.name')
                    ->label(__('Teams'))
                    ->badge()
                    ->separator(',')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('uploadedBy.display_name')
                    ->disabledClick()
                    ->label(__('Uploaded by'))
                    ->html()
                    ->formatStateUsing(fn ($state, SharedFile $record) => $record->uploadedBy
                        ? UserAvatar::html($record->uploadedBy)
                        : '—')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime()
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
