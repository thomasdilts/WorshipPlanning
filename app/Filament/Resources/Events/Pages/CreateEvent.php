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
     * How many services the last create made (a template can be copied to several weeks).
     */
    protected int $createdCount = 1;

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
        $copyOptions = [
            'weeks' => (int) ($data['weeks'] ?? 1),
            'copy_people_and_teams' => (bool) ($data['copy_people_and_teams'] ?? true),
            'copy_songs' => (bool) ($data['copy_songs'] ?? true),
        ];
        unset($data['service_template_id'], $data['weeks'], $data['copy_people_and_teams'], $data['copy_songs']);

        if (blank($templateId)) {
            return parent::handleRecordCreation($data);
        }

        abort_unless(auth()->user()?->can('useTemplates', Event::class), 403);

        $template = Event::templates()->where('church_id', $data['church_id'])->findOrFail($templateId);

        $copies = app(EventCopier::class)->copy($template, [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'starts_at' => $data['starts_at'],
            ...$copyOptions,
        ]);

        $this->createdCount = $copies->count();

        return $copies->first();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->createdCount > 1
            ? __(':count services copied', ['count' => $this->createdCount])
            : parent::getCreatedNotificationTitle();
    }
}
