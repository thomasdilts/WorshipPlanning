<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\Actions\CopyEventAction;
use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Service templates: complete services (activities, people, teams, songs) that new services are copied from.
 */
class ListServiceTemplates extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = EventResource::class;

    protected string $view = 'filament-panels::pages.page';

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->church_id !== null
            && $user->can('useTemplates', Event::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Service templates');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Create a new service from a template with the copy button. Church admins save services as templates from the services list.');
    }

    public function getBreadcrumbs(): array
    {
        return [
            EventResource::getUrl('index') => __('Services'),
            __('Service templates'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => EventResource::getEloquentQuery()
                ->withoutGlobalScope(Event::SERVICES_SCOPE)
                ->where('events.is_template', true)
                ->withCount('tasks'))
            ->defaultSort('name')
            ->paginated(false)
            ->emptyStateHeading(__('No service templates yet'))
            ->emptyStateDescription(__('Church admins can save any service as a template with the bookmark button in the services list.'))
            ->recordUrl(fn (Event $record): string => static::workspaceUrl($record))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Template'))
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label(__('Day and time'))
                    ->formatStateUsing(fn (Event $record): string => (string) $record->starts_at?->translatedFormat('l H:i')),
                TextColumn::make('tasks_count')
                    ->label(__('Tasks'))
                    ->numeric(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->limit(60)
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->recordActions([
                CopyEventAction::make(),
                Action::make('open')
                    ->label(fn (Event $record): string => auth()->user()?->can('update', $record) ? __('Edit') : __('View'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->color('gray')
                    ->url(fn (Event $record): string => static::workspaceUrl($record)),
                DeleteAction::make()
                    ->iconButton()
                    ->modalHeading(fn (Event $record): string => __('Delete template “:name”', ['name' => $record->name])),
            ]);
    }

    /**
     * Church admins edit a template; everyone else opens it read-only.
     */
    protected static function workspaceUrl(Event $record): string
    {
        return EventResource::getUrl(auth()->user()?->can('update', $record) ? 'edit' : 'view', ['record' => $record]);
    }
}
