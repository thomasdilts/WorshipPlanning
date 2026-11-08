<?php

namespace App\Filament\Resources\AutomaticMessages\Pages;

use App\Filament\Resources\AutomaticMessages\AutomaticMessageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAutomaticMessages extends ListRecords
{
    protected static string $resource = AutomaticMessageResource::class;

    public function getSubheading(): ?string
    {
        return __('Each automatic message is sent once to everyone assigned on every upcoming service, the chosen number of hours before it starts. People who declined are left out.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
