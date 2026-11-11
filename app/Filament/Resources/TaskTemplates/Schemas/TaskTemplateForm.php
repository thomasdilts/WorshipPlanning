<?php

namespace App\Filament\Resources\TaskTemplates\Schemas;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\FieldUsage;
use App\Models\TeamType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TaskTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        $teamsEnabled = static::teamsEnabled();
        $picturesEnabled = static::picturesEnabled();
        $fileSharingEnabled = static::fileSharingEnabled();
        $bibleVersesEnabled = static::bibleVersesEnabled();
        $songListEnabled = static::songListEnabled();
        $churchId = auth()->user()?->church_id;

        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(100)
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->label(__('Default description text'))
                    ->rows(3)
                    ->columnSpanFull()
                    ->helperText(__('Copied onto the service task when description usage is optional or required.')),
                Toggle::make('use_globally')
                    ->label(__('Use globally'))
                    ->default(false)
                    ->live()
                    ->helperText(__('Whole-service task (not on the timeline). Shown at the top of the service order.'))
                    ->afterStateUpdated(function (bool $state, callable $set): void {
                        if ($state) {
                            $set('default_duration', null);
                        } else {
                            $set('default_duration', 5);
                        }
                    }),
                TextInput::make('default_order')
                    ->label(__('Default order'))
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required()
                    ->helperText(fn (Get $get): string => (bool) $get('use_globally')
                        ? __('Order on the service’s people and teams tab. When not 0, also the column order in Tasks by service.')
                        : __('Starting position on the timeline. When not 0, also the column order in Tasks by service.')),
                TextInput::make('default_duration')
                    ->label(__('Default duration'))
                    ->numeric()
                    ->minValue(1)
                    ->suffix(__('minutes'))
                    ->default(5)
                    ->required(fn (Get $get): bool => ! (bool) $get('use_globally'))
                    ->visible(fn (Get $get): bool => ! (bool) $get('use_globally'))
                    ->helperText(__('Copied onto new service tasks; changing a task’s duration shifts later tasks.')),
                Section::make(__('Fields on the service-order task'))
                    ->description(__('Control whether each field appears on service tasks created from this template.'))
                    ->schema([
                        Select::make('person_usage')
                            ->label(__('Person'))
                            ->options(FieldUsage::options())
                            ->default(FieldUsage::Optional->value)
                            ->required()
                            ->native(false),
                        Select::make('team_usage')
                            ->label(__('Team'))
                            ->options(FieldUsage::options())
                            ->default(FieldUsage::NotUsed->value)
                            ->required()
                            ->native(false)
                            ->live()
                            ->visible($teamsEnabled)
                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                if ($state === FieldUsage::NotUsed->value || $state === null) {
                                    $set('team_type_id', null);
                                }
                            })
                            ->helperText($teamsEnabled
                                ? 'Requires the Teams module. Event messages to a team go to its leaders.'
                                : null),
                        Select::make('team_type_id')
                            ->label(__('Team type'))
                            ->options(fn (): array => TeamType::query()
                                ->where('church_id', $churchId)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->visible(function (Get $get) use ($teamsEnabled): bool {
                                if (! $teamsEnabled) {
                                    return false;
                                }

                                $usage = FieldUsage::tryFrom((string) $get('team_usage'));

                                return $usage?->isUsed() ?? false;
                            })
                            ->required(function (Get $get) use ($teamsEnabled): bool {
                                if (! $teamsEnabled) {
                                    return false;
                                }

                                $usage = FieldUsage::tryFrom((string) $get('team_usage'));

                                return $usage?->isUsed() ?? false;
                            })
                            ->helperText(__('Only teams of this type can be assigned on the service order.')),
                        Select::make('description_usage')
                            ->label(__('Description'))
                            ->options(FieldUsage::options())
                            ->default(FieldUsage::Optional->value)
                            ->required()
                            ->native(false),
                        Select::make('special_needs_usage')
                            ->label(__('Special needs'))
                            ->options(FieldUsage::options())
                            ->default(FieldUsage::Optional->value)
                            ->required()
                            ->native(false)
                            ->helperText(__('Notes for AV, accessibility, or other setup needs.')),
                        Select::make('song_usage')
                            ->label(__('Song'))
                            ->options(FieldUsage::options())
                            ->default(FieldUsage::NotUsed->value)
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                if ($state === FieldUsage::NotUsed->value || $state === null) {
                                    $set('allow_manual_song_entry', false);
                                }
                            })
                            ->helperText($songListEnabled
                                ? 'When required: pick from Songs → Song list, or a manual title if allowed below.'
                                : 'Free-text song title. Enable the Song list module for a catalog.'),
                        Toggle::make('allow_manual_song_entry')
                            ->label(__('Allow manual song entry'))
                            ->default(false)
                            ->visible(function (Get $get) use ($songListEnabled): bool {
                                if (! $songListEnabled) {
                                    return false;
                                }

                                $usage = FieldUsage::tryFrom((string) $get('song_usage'));

                                return $usage?->isUsed() ?? false;
                            })
                            ->helperText(__('When on, a typed title also satisfies a required song (catalog pick still counts).')),
                        Select::make('picture_usage')
                            ->label(__('Picture'))
                            ->options(FieldUsage::options())
                            ->default(FieldUsage::NotUsed->value)
                            ->required()
                            ->native(false)
                            ->visible($picturesEnabled)
                            ->helperText($picturesEnabled
                                ? 'Select from the church picture library (Pictures → Library).'
                                : null),
                        Select::make('file_usage')
                            ->label(__('Files'))
                            ->options(FieldUsage::options())
                            ->default(FieldUsage::NotUsed->value)
                            ->required()
                            ->native(false)
                            ->visible($fileSharingEnabled)
                            ->helperText($fileSharingEnabled
                                ? 'Attach shared files (Files → Shared files / team files).'
                                : null),
                        Select::make('bible_usage')
                            ->label(__('Bible verses'))
                            ->options(FieldUsage::options())
                            ->default(FieldUsage::NotUsed->value)
                            ->required()
                            ->native(false)
                            ->visible($bibleVersesEnabled)
                            ->helperText($bibleVersesEnabled
                                ? 'Pick passages from the Zamar Bible API (Bible → API settings).'
                                : null),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->default(true)
                    ->helperText(__('Inactive templates cannot be added to new services.')),
                Toggle::make('show_in_spreadsheet')
                    ->label(__('Show in task spreadsheet'))
                    ->default(false)
                    ->helperText(__('Always include this template as a column on Planning → Task spreadsheet (in addition to person/team columns).')),
            ]);
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
