<?php

namespace App\Filament\Resources\Teams;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Filament\Resources\Teams\Pages\CreateTeam;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\Pages\ViewTeam;
use App\Filament\Resources\Teams\RelationManagers\AssignedTasksRelationManager;
use App\Filament\Resources\Teams\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Teams\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\Teams\RelationManagers\SharedFilesRelationManager;
use App\Filament\Resources\Teams\RelationManagers\UnavailabilitiesRelationManager;
use App\Filament\Resources\Teams\Schemas\TeamForm;
use App\Filament\Resources\Teams\Tables\TeamsTable;
use App\Models\Team;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return null;
    }

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Team');
    }

    public static function getModelLabel(): string
    {
        return __('team');
    }

    public static function getPluralModelLabel(): string
    {
        return __('teams');
    }

    public static function form(Schema $schema): Schema
    {
        return TeamForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeamsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            AssignedTasksRelationManager::class,
            SharedFilesRelationManager::class,
            UnavailabilitiesRelationManager::class,
            MessagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeams::route('/'),
            'create' => CreateTeam::route('/create'),
            'view' => ViewTeam::route('/{record}'),
            'edit' => EditTeam::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withCount(['members', 'leaders']);

        $user = auth()->user();

        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        $churchId = app(CurrentChurch::class)->id() ?? $user->church_id;

        if ($churchId === null) {
            return $query->whereRaw('0 = 1');
        }

        $query->where('teams.church_id', $churchId);

        app(PermissionRegistrar::class)->setPermissionsTeamId($churchId);

        if ($user->can('teams.manage') || $user->can('teams.view')) {
            // Coordinators see everything; membership still floats to the top of the default sort.
            return $query
                ->orderByRaw(
                    '(select count(*) from team_user where team_user.team_id = teams.id and team_user.user_id = ?) desc',
                    [$user->id],
                )
                ->orderBy('teams.name');
        }

        return $query->whereHas('members', fn (Builder $q) => $q->where('users.id', $user->id));
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        if ($user?->church === null) {
            return false;
        }

        if (! app(ChurchModuleManager::class)->isEnabled($user->church, 'teams')) {
            return false;
        }

        return static::can('viewAny');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }
}
