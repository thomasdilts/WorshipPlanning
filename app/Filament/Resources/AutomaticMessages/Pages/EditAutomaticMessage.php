<?php

namespace App\Filament\Resources\AutomaticMessages\Pages;

use App\Filament\Resources\AutomaticMessages\AutomaticMessageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAutomaticMessage extends EditRecord
{
    protected static string $resource = AutomaticMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
