<?php

namespace App\Filament\Resources\Churches;

use App\Filament\Resources\Churches\Pages\ManageChurches;
use App\Models\Church;
use App\Models\Language;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

class ChurchResource extends Resource
{
    protected static ?string $model = Church::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 0;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Admin');
    }

    public static function getNavigationLabel(): string
    {
        return __('Churches');
    }

    public static function getModelLabel(): string
    {
        return __('church');
    }

    public static function getPluralModelLabel(): string
    {
        return __('churches');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, callable $set, ?Church $record): void {
                        if ($record !== null || blank($state)) {
                            return;
                        }

                        $set('slug', Str::slug($state));
                    }),
                TextInput::make('slug')
                    ->label(__('Slug'))
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->alphaDash(),
                TextInput::make('timezone')
                    ->label(__('Timezone'))
                    ->required()
                    ->maxLength(64)
                    ->default('Europe/Stockholm')
                    ->helperText(__('IANA timezone, e.g. Europe/Stockholm')),
                Select::make('locale')
                    ->label(__('Locale'))
                    ->options(fn (): array => Language::query()
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->pluck('name_en', 'iso')
                        ->all())
                    ->required()
                    ->default('en')
                    ->searchable(),
                Toggle::make('uses_24_hour_time')
                    ->label(__('Use 24-hour time'))
                    ->default(true),
                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->default(true),
                Section::make(__('First church admin (optional)'))
                    ->description(__('A new church has no email settings yet, so no invite can be sent — give the admin this password yourself. Leave blank to add people later.'))
                    ->visibleOn('create')
                    ->schema([
                        TextInput::make('admin_name')
                            ->label(__('Admin name'))
                            ->maxLength(255),
                        TextInput::make('admin_email')
                            ->label(__('Admin email'))
                            ->email()
                            ->live(onBlur: true)
                            ->maxLength(255),
                        TextInput::make('admin_password')
                            ->label(__('Admin password'))
                            ->password()
                            ->revealable()
                            ->rule(Password::defaults())
                            ->required(fn (Get $get): bool => filled($get('admin_email'))),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('Slug'))
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('timezone')
                    ->label(__('Timezone'))
                    ->toggleable(),
                TextColumn::make('locale')
                    ->label(__('Locale'))
                    ->badge(),
                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean(),
                TextColumn::make('users_count')
                    ->label(__('People'))
                    ->counts('users')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageChurches::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::can('viewAny');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }
}
