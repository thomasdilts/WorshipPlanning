<?php

namespace App\Filament\Resources\TaskTemplates\Pages;

use App\Enums\FieldUsage;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaskTemplate extends CreateRecord
{
    protected static string $resource = TaskTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['church_id'] = auth()->user()?->church_id;

        $usage = FieldUsage::tryFrom((string) ($data['team_usage'] ?? ''));

        if ($usage === null || ! $usage->isUsed()) {
            $data['team_type_id'] = null;
        }

        return $data;
    }
}
