<?php

namespace App\Filament\Resources\SharedFiles\Pages;

use App\Filament\Resources\SharedFiles\SharedFileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListSharedFiles extends ListRecords
{
    protected static string $resource = SharedFileResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Files for the whole church or specific teams — attach them on a service task.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Add file')),
        ];
    }
}
