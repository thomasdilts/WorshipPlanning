<?php

namespace App\Filament\Resources\AutomaticMessages;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\AutomaticMessages\Pages\CreateAutomaticMessage;
use App\Filament\Resources\AutomaticMessages\Pages\EditAutomaticMessage;
use App\Filament\Resources\AutomaticMessages\Pages\ListAutomaticMessages;
use App\Filament\Resources\AutomaticMessages\Schemas\AutomaticMessageForm;
use App\Filament\Resources\AutomaticMessages\Tables\AutomaticMessagesTable;
use App\Models\AutomaticMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Messages sent automatically to everyone on a service, a set number of hours before it starts.
 */
class AutomaticMessageResource extends Resource
{
    protected static ?string $model = AutomaticMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Admin');
    }

    public static function getNavigationLabel(): string
    {
        return __('Automatic messages');
    }

    public static function getModelLabel(): string
    {
        return __('automatic message');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Automatic messages');
    }

    public static function form(Schema $schema): Schema
    {
        return AutomaticMessageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AutomaticMessagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomaticMessages::route('/'),
            'create' => CreateAutomaticMessage::route('/create'),
            'edit' => EditAutomaticMessage::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! app(CurrentChurch::class)->check() && auth()->user()?->church_id === null) {
            return $query->whereRaw('0 = 1');
        }

        return $query;
    }
}
