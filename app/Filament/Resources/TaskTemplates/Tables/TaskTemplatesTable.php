<?php

namespace App\Filament\Resources\TaskTemplates\Tables;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\FieldUsage;
use App\Models\EventTask;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class TaskTemplatesTable
{
    public static function configure(Table $table): Table
    {
        $teamsEnabled = static::teamsEnabled();
        $picturesEnabled = static::picturesEnabled();
        $fileSharingEnabled = static::fileSharingEnabled();
        $bibleVersesEnabled = static::bibleVersesEnabled();

        return $table
            ->defaultSort('default_order')
            ->columns([
                TextColumn::make('default_order')
                    ->label(__('#'))
                    ->sortable()
                    ->width('4rem'),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                IconColumn::make('use_globally')
                    ->label(__('Global'))
                    ->boolean(),
                TextColumn::make('default_duration')
                    ->label(__('Duration'))
                    ->formatStateUsing(function ($state, $record): string {
                        if ($record->use_globally) {
                            return __('Global');
                        }

                        return $state ? $state.' min' : '—';
                    }),
                TextColumn::make('person_usage')
                    ->label(__('Person'))
                    ->badge()
                    ->formatStateUsing(fn (FieldUsage|string|null $state): string => static::usageLabel($state))
                    ->color(fn (FieldUsage|string|null $state): string => static::usageColor($state)),
                TextColumn::make('team_usage')
                    ->label(__('Team'))
                    ->badge()
                    ->formatStateUsing(fn (FieldUsage|string|null $state): string => static::usageLabel($state))
                    ->color(fn (FieldUsage|string|null $state): string => static::usageColor($state))
                    ->visible($teamsEnabled),
                TextColumn::make('teamType.name')
                    ->label(__('Team type'))
                    ->placeholder('—')
                    ->visible($teamsEnabled)
                    ->toggleable(),
                TextColumn::make('description_usage')
                    ->label(__('Desc'))
                    ->badge()
                    ->formatStateUsing(fn (FieldUsage|string|null $state): string => static::usageLabel($state))
                    ->color(fn (FieldUsage|string|null $state): string => static::usageColor($state))
                    ->toggleable(),
                TextColumn::make('special_needs_usage')
                    ->label(__('Needs'))
                    ->badge()
                    ->formatStateUsing(fn (FieldUsage|string|null $state): string => static::usageLabel($state))
                    ->color(fn (FieldUsage|string|null $state): string => static::usageColor($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('song_usage')
                    ->label(__('Song'))
                    ->badge()
                    ->formatStateUsing(fn (FieldUsage|string|null $state): string => static::usageLabel($state))
                    ->color(fn (FieldUsage|string|null $state): string => static::usageColor($state))
                    ->toggleable(),
                IconColumn::make('allow_manual_song_entry')
                    ->label(__('Manual song'))
                    ->boolean()
                    ->visible(static::songListEnabled())
                    ->toggleable(),
                TextColumn::make('picture_usage')
                    ->label(__('Picture'))
                    ->badge()
                    ->formatStateUsing(fn (FieldUsage|string|null $state): string => static::usageLabel($state))
                    ->color(fn (FieldUsage|string|null $state): string => static::usageColor($state))
                    ->visible($picturesEnabled)
                    ->toggleable(),
                TextColumn::make('file_usage')
                    ->label(__('Files'))
                    ->badge()
                    ->formatStateUsing(fn (FieldUsage|string|null $state): string => static::usageLabel($state))
                    ->color(fn (FieldUsage|string|null $state): string => static::usageColor($state))
                    ->visible($fileSharingEnabled)
                    ->toggleable(),
                TextColumn::make('bible_usage')
                    ->label(__('Bible'))
                    ->badge()
                    ->formatStateUsing(fn (FieldUsage|string|null $state): string => static::usageLabel($state))
                    ->color(fn (FieldUsage|string|null $state): string => static::usageColor($state))
                    ->visible($bibleVersesEnabled)
                    ->toggleable(),
                IconColumn::make('show_in_spreadsheet')
                    ->label(__('Spreadsheet'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('Active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalDescription(function (Collection $records): string {
                            $count = EventTask::query()
                                ->withoutGlobalScope(EventTask::SERVICES_SCOPE)
                                ->whereIn('task_template_id', $records->modelKeys())
                                ->distinct()
                                ->count('event_id');

                            return $count === 0
                                ? __('filament-actions::modal.confirmation')
                                : __('These tasks are used in :count services and service templates. Deleting it also removes them, with its assignments and answers, from all of them — past services included. This cannot be undone.', ['count' => $count]);
                        }),
                ]),
            ]);
    }

    protected static function usageLabel(FieldUsage|string|null $state): string
    {
        if ($state instanceof FieldUsage) {
            return $state->label();
        }

        return FieldUsage::tryFrom((string) $state)?->label() ?? '—';
    }

    protected static function usageColor(FieldUsage|string|null $state): string
    {
        $usage = $state instanceof FieldUsage
            ? $state
            : FieldUsage::tryFrom((string) $state);

        return match ($usage) {
            FieldUsage::Required => 'danger',
            FieldUsage::Optional => 'warning',
            FieldUsage::NotUsed => 'gray',
            default => 'gray',
        };
    }

    protected static function teamsEnabled(): bool
    {
        return static::moduleEnabled('teams');
    }

    protected static function picturesEnabled(): bool
    {
        return static::moduleEnabled('pictures');
    }

    protected static function fileSharingEnabled(): bool
    {
        return static::moduleEnabled('file_sharing');
    }

    protected static function bibleVersesEnabled(): bool
    {
        return static::moduleEnabled('bible_verses');
    }

    protected static function songListEnabled(): bool
    {
        return static::moduleEnabled('song_list');
    }

    protected static function moduleEnabled(string $key): bool
    {
        $church = auth()->user()?->church;

        if ($church === null) {
            return false;
        }

        return app(ChurchModuleManager::class)->isEnabled($church, $key);
    }
}
