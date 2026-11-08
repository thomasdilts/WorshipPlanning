<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\Concerns\HasEventMessageActions;
use App\Filament\Resources\Events\EventResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;

class ViewEvent extends ViewRecord
{
    use HasEventMessageActions;

    /** @var array<string, string> */
    protected array $extraBodyAttributes = [
        'class' => 'wp-service-order',
    ];

    protected static string $resource = EventResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $event = $this->getRecord();

        return $event->is_template
            ? __('Service template').' · '.$event->starts_at->timezone(config('app.timezone'))->translatedFormat('l H:i')
            : $event->starts_at->timezone(config('app.timezone'))->translatedFormat('D j M Y H:i');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                SchemaView::make('filament.events.staffing-summary'),
                SchemaView::make('filament.events.workspace-tasks'),
            ]);
    }

    #[On('switch-service-tab')]
    public function switchServiceTab(string $tab): void
    {
        abort_unless(in_array($tab, ['0', '1'], true), 422);
        $this->activeRelationManager = $tab;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
