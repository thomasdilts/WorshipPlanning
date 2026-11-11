<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\Actions\CopyEventAction;
use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\User;
use App\Services\EventCopier;
use App\Support\DateTimeFormats;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Exists;

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
        return __('Use templates when creating services. Church admins can add a template from any existing service.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createTemplate')
                ->label(__('Add service template'))
                ->icon(Heroicon::OutlinedPlus)
                ->authorize(fn (): bool => auth()->user()?->can('service_templates.manage') ?? false)
                ->modalHeading(__('Add service template'))
                ->modalDescription(__('Choose an existing service to copy its tasks, people, teams and songs into a reusable template.'))
                ->modalSubmitActionLabel(__('Save template'))
                ->schema([
                    Select::make('source_event_id')
                        ->label(__('Existing service'))
                        ->placeholder(__('Choose a service'))
                        ->helperText(__('Search by service name or date (YYYY-MM-DD).'))
                        ->searchable()
                        ->options(fn (): array => $this->serviceOptions())
                        ->getSearchResultsUsing(fn (string $search): array => $this->serviceOptions($search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => EventResource::getEloquentQuery()->find($value)?->name)
                        ->exists('events', 'id', modifyRuleUsing: fn (Exists $rule): Exists => $rule
                            ->where('church_id', auth()->user()?->church_id)
                            ->where('is_template', false))
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            $source = filled($state) ? EventResource::getEloquentQuery()->find($state) : null;

                            if ($source !== null) {
                                $set('name', $source->name);
                            }
                        }),
                    TextInput::make('name')
                        ->label(__('Template name'))
                        ->required()
                        ->maxLength(100),
                ])
                ->action(function (array $data): void {
                    $source = EventResource::getEloquentQuery()->findOrFail($data['source_event_id']);
                    abort_unless(auth()->user()?->can('saveAsTemplate', $source), 403);

                    app(EventCopier::class)->copy($source, [
                        'name' => $data['name'],
                        'starts_at' => $source->starts_at,
                        'copy_people_and_teams' => true,
                        'copy_songs' => true,
                        'as_template' => true,
                    ]);

                    Notification::make()->title(__('Service template saved'))->success()->send();
                    $this->resetTable();
                }),
        ];
    }

    /** @return array<int, string> */
    protected function serviceOptions(?string $search = null): array
    {
        return EventResource::getEloquentQuery()
            ->when(filled($search), fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('starts_at', 'like', '%'.$search.'%')))
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Event $event): array => [$event->id => $event->name.' · '.$event->starts_at->translatedFormat(DateTimeFormats::dateTime())])
            ->all();
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
            ->emptyStateDescription(__('Church admins can use Add service template to start from an existing service.'))
            ->recordUrl(fn (Event $record): string => static::workspaceUrl($record))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Template'))
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label(__('Day and time'))
                    ->formatStateUsing(fn (Event $record): string => (string) $record->starts_at?->translatedFormat('l '.DateTimeFormats::time())),
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
