<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\Concerns\HasEventMessageActions;
use App\Filament\Resources\Events\EventResource;
use App\Support\DateTimeFormats;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

class EditEvent extends EditRecord
{
    use HasEventMessageActions;

    /** @var array<string, string> */
    protected array $extraBodyAttributes = [
        'class' => 'wp-service-order',
    ];

    protected static string $resource = EventResource::class;

    #[Url]
    public ?string $return = null;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $event = $this->getRecord();
        $bits = $event->is_template
            ? [__('Service template'), $event->starts_at->timezone(config('app.timezone'))->translatedFormat('l '.DateTimeFormats::time())]
            : [$event->starts_at->timezone(config('app.timezone'))->translatedFormat(DateTimeFormats::dateWithWeekdayTime())];

        if (filled($event->description)) {
            $bits[] = str($event->description)->limit(80);
        }

        return implode(' · ', $bits);
    }

    public function getBreadcrumb(): string
    {
        return __('Service workspace');
    }

    /**
     * Workspace page: Order / People tabs via relation managers. Details live on EditEventDetails.
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
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

    protected function getFormActions(): array
    {
        return [];
    }
}
