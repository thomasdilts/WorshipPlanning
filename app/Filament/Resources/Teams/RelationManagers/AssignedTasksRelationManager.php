<?php

namespace App\Filament\Resources\Teams\RelationManagers;

use App\Core\Modules\ChurchModuleManager;
use App\Filament\Tables\AssignedTasksTable;
use App\Models\Team;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AssignedTasksRelationManager extends RelationManager
{
    protected static string $relationship = 'eventTasks';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Tasks');
    }

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        if (! $ownerRecord instanceof Team) {
            return false;
        }

        $user = auth()->user();

        if ($user?->church === null) {
            return false;
        }

        if (! app(ChurchModuleManager::class)->isEnabled($user->church, 'teams')) {
            return false;
        }

        return $user->can('view', $ownerRecord) && $user->can('events.view');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return AssignedTasksTable::configure(
            $table
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['event', 'template', 'assignedUser', 'responses']))
                ->emptyStateHeading(__('No tasks for this team'))
                ->emptyStateDescription(__('When this team is assigned on a service, those tasks appear here.')),
            options: ['show_assignee' => false],
        );
    }
}
