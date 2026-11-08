<?php

namespace App\Filament\Resources\AutomaticMessages\Pages;

use App\Filament\Resources\AutomaticMessages\AutomaticMessageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAutomaticMessage extends CreateRecord
{
    protected static string $resource = AutomaticMessageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['church_id'] = auth()->user()?->church_id;
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}
