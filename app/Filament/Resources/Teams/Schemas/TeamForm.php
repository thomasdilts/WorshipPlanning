<?php

namespace App\Filament\Resources\Teams\Schemas;

use App\Models\TeamType;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        $churchId = auth()->user()?->church_id;

        $churchUsers = fn (): array => User::query()
            ->where('church_id', $churchId)
            ->orderBy('display_name')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user) => [
                $user->id => $user->display_name ?: $user->name,
            ])
            ->all();

        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(100),
                Select::make('team_type_id')
                    ->label(__('Team type'))
                    ->options(fn (): array => TeamType::query()
                        ->where('church_id', $churchId)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText(__('Create types under Teams → Team types (e.g. Music team, Prayer team).')),
                Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull(),
                Section::make(__('Roster'))
                    ->description(__('Every team needs at least one leader. Leaders run their team: members, leaders, availability and files.'))
                    ->visibleOn('create')
                    ->schema([
                        Select::make('member_ids')
                            ->label(__('Members'))
                            ->multiple()
                            ->options($churchUsers)
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->helperText(__('Include every leader in the member list.')),
                        Select::make('leader_ids')
                            ->label(__('Team leaders'))
                            ->multiple()
                            ->options(function (callable $get) use ($churchUsers): array {
                                $all = $churchUsers();
                                $memberIds = array_map('intval', $get('member_ids') ?? []);

                                if ($memberIds === []) {
                                    return $all;
                                }

                                return array_intersect_key($all, array_flip($memberIds));
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText(__('One or more. Leading a team gives these powers for that team — no special role is needed.')),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
