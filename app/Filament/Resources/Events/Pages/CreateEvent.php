<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Services\EventCopier;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['church_id'] = auth()->user()?->church_id;
        $data['starts_at'] = Carbon::parse($data['starts_at'])->setTimeFromTimeString($data['start_time'])->startOfMinute();
        unset($data['start_time']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $templateId = $data['service_template_id'] ?? null;
        unset($data['service_template_id']);

        if (blank($templateId)) {
            return parent::handleRecordCreation($data);
        }

        abort_unless(auth()->user()?->can('useTemplates', Event::class), 403);

        $template = Event::templates()->where('church_id', $data['church_id'])->findOrFail($templateId);

        return app(EventCopier::class)->copy($template, [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'starts_at' => $data['starts_at'],
            'copy_people_and_teams' => true,
            'copy_songs' => true,
        ])->sole();
    }
}
