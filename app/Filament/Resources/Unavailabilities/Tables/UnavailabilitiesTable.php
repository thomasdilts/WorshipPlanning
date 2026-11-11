<?php

namespace App\Filament\Resources\Unavailabilities\Tables;

use App\Filament\Resources\Unavailabilities\UnavailabilityResource;
use App\Filament\Tables\DatePeriodFilter;
use App\Models\Unavailability;
use App\Support\UserAvatar;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UnavailabilitiesTable
{
    public static function configure(Table $table): Table
    {
        $canManageOthers = UnavailabilityResource::canManageOthers();

        return $table
            ->defaultSort('starts_on', 'asc')
            ->recordClasses(fn (Unavailability $record): ?string => $record->ends_on->lt(today()) ? 'wp-unavailability-past' : null)
            ->columns([
                TextColumn::make('subject')
                    ->disabledClick()
                    ->label(__('Who'))
                    ->html()
                    ->state(function (Unavailability $record) {
                        if ($record->isForTeam()) {
                            return e($record->subjectLabel());
                        }

                        return $record->user
                            ? UserAvatar::html($record->user)
                            : e($record->subjectLabel());
                    })
                    ->description(fn (Unavailability $record): string => $record->isForTeam() ? __('Team') : __('Person'))
                    ->sortable(false)
                    ->visible($canManageOthers),
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
            ->filters([
                DatePeriodFilter::make('ends_on'),
                SelectFilter::make('kind')
                    ->label(__('Type'))
                    ->options([
                        'user' => __('Person'),
                        'team' => __('Team'),
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['value'] ?? null) {
                            'user' => $query->personal(),
                            'team' => $query->forTeams(),
                            default => $query,
                        };
                    })
                    ->visible($canManageOthers),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(2)
            ->deferFilters(false)
            ->hiddenFilterIndicators()
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
