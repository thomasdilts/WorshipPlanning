<?php

namespace App\Filament\Resources\Teams\RelationManagers;

use App\Models\Team;
use App\Models\User;
use App\Support\UserAvatar;
use Filament\Actions\Action;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Teams\Services\TeamRosterService;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Members');
    }

    /**
     * Anyone who can open the team sees its roster; changing it is gated by manageMembers.
     * Without this, Filament would require the church-wide "view people" permission.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Team
            && (auth()->user()?->can('view', $ownerRecord) ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        /** @var Team $team */
        $team = $this->getOwnerRecord();
        $canManage = auth()->user()?->can('manageMembers', $team) ?? false;

        return $table
            ->recordTitleAttribute('display_name')
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('Name'))
                    ->disabledClick()
                    ->html()
                    ->formatStateUsing(fn ($state, User $record) => UserAvatar::html($record))
                    ->searchable(['display_name', 'name']),
                TextColumn::make('email')
                    ->toggleable(),
                IconColumn::make('pivot.is_leader')
                    ->label(__('Leader'))
                    ->boolean(),
            ])
            ->headerActions([
                Action::make('addMember')
                    ->label(__('Add member'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible($canManage)
                    ->form([
                        Select::make('user_id')
                            ->label(__('Person'))
                            ->options(fn (): array => User::query()
                                ->where('church_id', $team->church_id)
                                ->whereDoesntHave('teams', fn ($q) => $q->where('teams.id', $team->id))
                                ->orderBy('display_name')
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (User $user) => [
                                    $user->id => UserAvatar::html($user)->toHtml(),
                                ])
                                ->all())
                            ->allowHtml()
                            ->searchable()
                            ->required(),
                        Toggle::make('is_leader')
                            ->label(__('Team leader'))
                            ->default(false),
                    ])
                    ->action(function (array $data) use ($team): void {
                        try {
                            app(TeamRosterService::class)->addMember(
                                $team,
                                User::query()->findOrFail($data['user_id']),
                                (bool) ($data['is_leader'] ?? false),
                            );
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('toggleLeader')
                    ->label(fn (User $record): string => $record->pivot->is_leader ? 'Remove leader' : 'Make leader')
                    ->icon(fn (User $record) => $record->pivot->is_leader
                        ? Heroicon::OutlinedArrowDownCircle
                        : Heroicon::OutlinedStar)
                    ->visible($canManage)
                    ->action(function (User $record) use ($team): void {
                        try {
                            app(TeamRosterService::class)->setLeader(
                                $team,
                                $record,
                                ! (bool) $record->pivot->is_leader,
                            );
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                DetachAction::make()
                    ->label(__('Remove'))
                    ->visible($canManage)
                    ->using(function (User $record) use ($team): void {
                        try {
                            app(TeamRosterService::class)->removeMember($team, $record);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            throw $e;
                        }
                    }),
            ]);
    }

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->can('manageMembers', $this->getOwnerRecord()) ?? false);
    }
}
