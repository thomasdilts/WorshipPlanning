<?php

namespace App\Filament\Pages;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Models\User;
use App\Support\OutboundUrlGuard;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\Client\RequestException;
use Modules\BibleVerses\Services\ZamarBibleClient;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

class BibleSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Bible');
    }

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('API settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Bible API settings');
    }

    protected string $view = 'filament-panels::pages.page';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->church_id === null || $user->church === null) {
            return false;
        }

        $manager = app(ChurchModuleManager::class);

        if (! $manager->isEnabled($user->church, 'bible_verses')) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('bible_verses.manage');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;
        abort_unless($church, 403);

        $settings = app(ZamarBibleClient::class)->settings($church);

        $this->form->fill([
            'base_url' => $settings['base_url'],
            'api_key' => '',
            'default_bible' => $settings['default_bible'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Zamar Bible API'))
                    ->description(__('Loads verse text from Zamar. Create an API key in Zamar, then paste it here.'))
                    ->schema([
                        TextInput::make('base_url')
                            ->label(__('Base URL'))
                            ->url()
                            ->required()
                            ->rule(fn (): \Closure => app(OutboundUrlGuard::class)->urlRule())
                            ->placeholder('https://bible.zamarapp.com')
                            ->helperText(__('Host only (trailing /api is stripped). e.g. https://bible.zamarapp.com')),
                        TextInput::make('api_key')
                            ->label(__('API key'))
                            ->password()
                            ->revealable()
                            ->helperText(__('Leave blank to keep the current key. Stored encrypted.')),
                        TextInput::make('default_bible')
                            ->label(__('Default translation code'))
                            ->required()
                            ->maxLength(20)
                            ->helperText(__('Zamar bible code such as kjv or svd.')),
                    ]),
            ])
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
                                ->label(__('Save settings'))
                                ->submit('save'),
                            Action::make('testConnection')
                                ->label(__('Test connection'))
                                ->color('gray')
                                ->action('testConnection'),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;
        abort_unless($church, 403);

        $data = $this->form->getState();
        $manager = app(ChurchModuleManager::class);
        $existing = $manager->settings($church, 'bible_verses');

        $apiKey = filled($data['api_key'] ?? null)
            ? $manager->encryptSecret((string) $data['api_key'])
            : ($existing['api_key'] ?? null);

        $manager->updateSettings($church, 'bible_verses', [
            'base_url' => ZamarBibleClient::normalizeBaseUrl((string) $data['base_url']),
            'api_key' => $apiKey,
            'default_bible' => strtolower((string) $data['default_bible']),
        ]);

        Notification::make()
            ->title(__('Bible API settings saved'))
            ->success()
            ->send();
    }

    public function testConnection(): void
    {
        $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;
        abort_unless($church, 403);

        // Persist current form fields first so the test uses what is on screen.
        $this->save();

        try {
            $bibles = app(ZamarBibleClient::class)->bibles($church);
            $count = count($bibles);

            Notification::make()
                ->title($count > 0 ? __('Connected — :count bible(s)', ['count' => $count]) : __('Connected, but no bibles returned'))
                ->{$count > 0 ? 'success' : 'warning'}()
                ->send();
        } catch (\Throwable $e) {
            // The far server's reply is not shown: the address is typed in by the church.
            report($e);

            Notification::make()
                ->title(__('Connection failed'))
                ->body($e instanceof RequestException
                    ? __('The Bible API answered with status :status. Check the address and API key.', ['status' => $e->response->status()])
                    : __('The Bible API could not be reached. Check the address and API key.'))
                ->danger()
                ->send();
        }
    }
}
