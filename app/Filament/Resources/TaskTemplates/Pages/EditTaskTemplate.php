<?php

namespace App\Filament\Resources\TaskTemplates\Pages;

use App\Enums\FieldUsage;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use App\Models\TaskTemplate;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTaskTemplate extends EditRecord
{
    protected static string $resource = TaskTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(function (TaskTemplate $record): string {
                    $count = $record->usedInEventsCount();

                    return $count === 0
                        ? __('filament-actions::modal.confirmation')
                        : __('This task is used in :count services and service templates. Deleting it also removes it, with its assignments and answers, from all of them — past services included. This cannot be undone.', ['count' => $count]);
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $usage = FieldUsage::tryFrom((string) ($data['team_usage'] ?? ''));

        if ($usage === null || ! $usage->isUsed()) {
            $data['team_type_id'] = null;
        }

        return $data;
    }
}
