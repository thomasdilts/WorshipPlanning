<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class EditEventDetails extends EditRecord
{
    protected static string $resource = EventResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('Edit service details');
    }

    public function getBreadcrumb(): string
    {
        return __('Details');
    }

    public function getRelationManagers(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToEvent')
                ->label(__('Back to service'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => EventResource::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return EventResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
