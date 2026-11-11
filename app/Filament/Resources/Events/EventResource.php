<?php

namespace App\Filament\Resources\Events;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\EditEventDetails;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ListServiceTemplates;
use App\Filament\Resources\Events\Pages\ManageEventMessages;
use App\Filament\Resources\Events\Pages\TaskSpreadsheet;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\OrderTasksRelationManager;
use App\Filament\Resources\Events\RelationManagers\PeopleTasksRelationManager;
use App\Filament\Resources\Events\Schemas\EventForm;
use App\Filament\Resources\Events\Schemas\EventInfolist;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return null;
    }

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Services');
    }

    public static function getBreadcrumb(): string
    {
        return __('Services');
    }

    public static function getModelLabel(): string
    {
        return __('service');
    }

    public static function getPluralModelLabel(): string
    {
        return __('services');
    }

    public static function form(Schema $schema): Schema
    {
        return EventForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            OrderTasksRelationManager::class,
            PeopleTasksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'task-spreadsheet' => TaskSpreadsheet::route('/task-spreadsheet'),
            'templates' => ListServiceTemplates::route('/templates'),
            'create' => CreateEvent::route('/create'),
            'view' => ViewEvent::route('/{record}'),
            'edit' => EditEvent::route('/{record}/edit'),
            'edit-details' => EditEventDetails::route('/{record}/edit-details'),
            'messages' => ManageEventMessages::route('/{record}/messages'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! app(CurrentChurch::class)->check()) {
            return $query->whereRaw('0 = 1');
        }

        return $query;
    }

    /**
     * Service templates open in the same pages as services, so record URLs also resolve templates.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery()->withoutGlobalScope(Event::SERVICES_SCOPE);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }
}
