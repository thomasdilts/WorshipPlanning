<?php

namespace App\Filament\Pages;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\CurrentChurch;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

class ManageModules extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Admin');
    }

    protected static ?int $navigationSort = 18;

    public static function getNavigationLabel(): string
    {
        return __('Modules');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Modules');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Turn on only what your church will use. You can try a module and turn it off later if nobody needs it.');
    }

    protected string $view = 'filament-panels::pages.page';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->church_id === null) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('modules.manage');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;

        abort_unless($church, 403);

        $manager = app(ChurchModuleManager::class);
        $state = [];

        foreach (app(ModuleRegistry::class)->all() as $definition) {
            $state["module_{$definition->key}"] = $definition->required
                || $manager->isEnabled($church, $definition->key);
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        $registry = app(ModuleRegistry::class);

        foreach ($registry->all() as $definition) {
            $isLocked = $registry->isLocked($definition->key);

            $sections[] = Section::make(__($definition->name))
                ->description(filled($definition->description) ? __($definition->description) : null)
                ->schema([
                    Toggle::make("module_{$definition->key}")
                        ->label(match (true) {
                            $definition->required => __('Always on'),
                            $isLocked => __('Locked in demo'),
                            default => __('Enable for this church'),
                        })
                        ->disabled($definition->required || $isLocked)
                        ->dehydrated(true),
                ]);
        }

        return $schema
            ->components($sections)
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label(__('Save modules'))
                                ->submit('save'),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;
        abort_unless($church, 403);

        $manager = app(ChurchModuleManager::class);
        $registry = app(ModuleRegistry::class);
        $data = $this->form->getState();

        foreach ($registry->all() as $definition) {
            if ($definition->required || $registry->isLocked($definition->key)) {
                continue;
            }

            $enabled = (bool) ($data["module_{$definition->key}"] ?? false);

            if ($enabled) {
                $manager->enable($church, $definition->key);
            } else {
                $manager->disable($church, $definition->key);
            }
        }

        Notification::make()
            ->title(__('Modules updated'))
            ->success()
            ->send();
    }
}
