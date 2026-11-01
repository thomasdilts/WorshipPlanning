<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListTeams extends ListRecords
{
    protected static string $resource = TeamResource::class;

    /** @var array<string, string> */
    protected array $extraBodyAttributes = [
        'class' => 'wp-teams-list',
    ];

    public function getSubheading(): string|Htmlable|null
    {
        return __('Rosters for music, prayer, greeters, and more. Open a team to manage members.');
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $mine = Tab::make(__('My teams'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereHas('members', fn (Builder $q) => $q->where('users.id', $user->id)));

        if (! $user->can('teams.manage') && ! $user->can('teams.view')) {
            return [
                'mine' => $mine,
            ];
        }

        return [
            'mine' => $mine,
            'all' => Tab::make(__('All teams')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
