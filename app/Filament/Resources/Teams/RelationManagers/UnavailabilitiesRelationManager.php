<?php

namespace App\Filament\Resources\Teams\RelationManagers;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Team;
use App\Models\Unavailability;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

class UnavailabilitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'unavailabilities';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Unavailability');
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

        if (! app(ChurchModuleManager::class)->isEnabled($user->church, 'unavailability')) {
            return false;
        }

        if (! app(ChurchModuleManager::class)->isEnabled($user->church, 'teams')) {
            return false;
        }

        return $user->can('view', $ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->formSchema());
    }

    public function table(Table $table): Table
    {
        /** @var Team $team */
        $team = $this->getOwnerRecord();
        $canManage = $this->canManageTeamUnavailability($team);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->forTeams()->orderByDesc('starts_on'))
            ->columns([
                TextColumn::make('starts_on')
                    ->label(__('Starts'))
                    ->date('M j, Y')
                    ->sortable(),
                TextColumn::make('ends_on')
                    ->label(__('Ends'))
                    ->date('M j, Y')
                    ->sortable(),
                TextColumn::make('note')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('addUnavailability')
                    ->label(__('Add unavailability'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalHeading(__('Add team unavailability'))
                    ->visible($canManage)
                    ->schema($this->formSchema())
                    ->action(function (array $data) use ($team): void {
                        Unavailability::query()->create([
                            'church_id' => $team->church_id,
                            'team_id' => $team->id,
                            'user_id' => null,
                            'starts_on' => $data['starts_on'],
                            'ends_on' => $data['ends_on'],
                            'note' => $data['note'] ?? null,
                        ]);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible($canManage)
                    ->schema($this->formSchema()),
                DeleteAction::make()
                    ->visible($canManage),
            ])
            ->emptyStateHeading(__('No team unavailability'))
            ->emptyStateDescription(__('Mark dates when this whole team cannot serve.'))
            ->emptyStateActions([
                Action::make('addUnavailability')
                    ->label(__('Add unavailability'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible($canManage)
                    ->schema($this->formSchema())
                    ->action(function (array $data) use ($team): void {
                        Unavailability::query()->create([
                            'church_id' => $team->church_id,
                            'team_id' => $team->id,
                            'user_id' => null,
                            'starts_on' => $data['starts_on'],
                            'ends_on' => $data['ends_on'],
                            'note' => $data['note'] ?? null,
                        ]);
                    }),
            ]);
    }

    /**
     * @return list<Component>
     */
    protected function formSchema(): array
    {
        return [
            DatePicker::make('starts_on')
                ->label(__('Starts'))
                ->required()
                ->native(false)
                ->live(onBlur: true),
            DatePicker::make('ends_on')
                ->label(__('Ends'))
                ->required()
                ->native(false)
                ->afterOrEqual('starts_on'),
            TextInput::make('note')
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }

    public function isReadOnly(): bool
    {
        $team = $this->getOwnerRecord();

        return ! ($team instanceof Team && $this->canManageTeamUnavailability($team));
    }

    protected function canManageTeamUnavailability(Team $team): bool
    {
        $user = auth()->user();

        if ($user === null || $user->church_id === null) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        if ($user->can('unavailability.manage') || $user->can('teams.manage')) {
            return true;
        }

        return $team->isLeader($user);
    }
}
