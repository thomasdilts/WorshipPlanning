<?php

namespace App\Filament\Resources\Unavailabilities;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Unavailabilities\Pages\CreateUnavailability;
use App\Filament\Resources\Unavailabilities\Pages\EditUnavailability;
use App\Filament\Resources\Unavailabilities\Pages\ListUnavailabilities;
use App\Filament\Resources\Unavailabilities\Schemas\UnavailabilityForm;
use App\Filament\Resources\Unavailabilities\Tables\UnavailabilitiesTable;
use App\Models\Unavailability;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\PermissionRegistrar;

class UnavailabilityResource extends Resource
{
    protected static ?string $model = Unavailability::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'note';

    public static function getNavigationLabel(): string
    {
        return __('Unavailable');
    }

    public static function getModelLabel(): string
    {
        return __('unavailable date');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Unavailable');
    }

    protected static ?string $slug = 'unavailabilities';

    public static function form(Schema $schema): Schema
    {
        return UnavailabilityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UnavailabilitiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnavailabilities::route('/'),
            'create' => CreateUnavailability::route('/create'),
            'edit' => EditUnavailability::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['user', 'team']);

        $user = auth()->user();

        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        $churchId = app(CurrentChurch::class)->id() ?? $user->church_id;

        if ($churchId === null) {
            return $query->whereRaw('0 = 1');
        }

        $query->where('unavailabilities.church_id', $churchId);

        app(PermissionRegistrar::class)->setPermissionsTeamId($churchId);

        if ($user->can('unavailability.manage') || $user->can('unavailability.view')) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }

    /**
     * The church-wide list is only for people who may see others' dates; own dates live on MyUnavailability.
     */
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        if ($user?->church === null) {
            return false;
        }

        if (! app(ChurchModuleManager::class)->isEnabled($user->church, 'unavailability')) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        if (! $user->can('unavailability.view') && ! $user->can('unavailability.manage')) {
            return false;
        }

        return static::can('viewAny');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canManageOthers(): bool
    {
        $user = auth()->user();

        if ($user?->church_id === null) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('unavailability.manage');
    }
}
