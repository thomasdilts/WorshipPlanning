<?php

namespace App\Filament\Pages;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
use Modules\Messaging\Services\WhatsAppTemplates;
use Modules\Messaging\Support\MessagingAvailability;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

class WhatsAppMessagingSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Admin');
    }

    protected static ?int $navigationSort = 57;

    public static function getNavigationLabel(): string
    {
        return __('WhatsApp settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('WhatsApp messaging settings');
    }

    protected static ?string $slug = 'whatsapp-messaging-settings';

    protected string $view = 'filament-panels::pages.page';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->church_id === null || $user->church === null) {
            return false;
        }

        if (! app(ChurchModuleManager::class)->isEnabled($user->church, MessagingAvailability::MODULE_WHATSAPP)) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('messaging.settings.manage');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;
        abort_unless($church, 403);

        $settings = app(ChurchModuleManager::class)->settings($church, MessagingAvailability::MODULE_WHATSAPP);

        $this->form->fill([
            'api_version' => $settings['api_version'] ?? 'v21.0',
            'access_token' => '',
            'phone_number_id' => $settings['phone_number_id'] ?? '',
            'business_account_id' => $settings['business_account_id'] ?? '',
            'confirmation_whatsapp_template' => $settings['confirmation_whatsapp_template'] ?? null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Meta WhatsApp Cloud API'))
                    ->description(__('WhatsApp via Meta. Use the permanent access token and Phone number ID from Meta’s WhatsApp setup.'))
                    ->schema([
                        TextInput::make('api_version')
                            ->label(__('Graph API version'))
                            ->required()
                            ->default('v21.0'),
                        TextInput::make('access_token')
                            ->label(__('Access token'))
                            ->password()
                            ->revealable()
                            ->helperText(__('Leave blank to keep the current token.')),
                        TextInput::make('phone_number_id')
                            ->label(__('Phone number ID'))
                            ->required(),
                        TextInput::make('business_account_id')
                            ->label(__('WhatsApp Business Account ID'))
                            ->helperText(__('Needed to send approved message templates. Found in WhatsApp Manager; not the Business portfolio ID.')),
                    ]),
                Section::make(__('Preferred messaging confirmation'))
                    ->description(__('Sent by WhatsApp when someone chooses WhatsApp as their preferred messaging. The choice is refused if it cannot be sent.'))
                    ->schema([
                        Select::make('confirmation_whatsapp_template')
                            ->label(__('Meta template'))
                            ->options(fn (?string $state): array => $this->whatsAppTemplateOptions($state))
                            ->searchable()
                            ->helperText(fn (): string => trim(($this->whatsAppTemplateError() ?? '').' '.__('Until one is chosen, nobody can switch to WhatsApp. The templates load once the access token and account ID are saved.'))),
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
                        ]),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    protected function whatsAppTemplateOptions(?string $current): array
    {
        $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;
        $options = $church === null ? [] : app(WhatsAppTemplates::class)->options($church);

        // Keep the saved choice selectable when Meta cannot be reached.
        if (filled($current) && ! array_key_exists($current, $options)) {
            $options[$current] = $current;
        }

        return $options;
    }

    protected function whatsAppTemplateError(): ?string
    {
        $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;

        if ($church === null) {
            return null;
        }

        $templates = app(WhatsAppTemplates::class);
        $templates->all($church);
        $error = $templates->fetchError($church);

        return $error === null ? null : __('WhatsApp templates could not be loaded: :error', ['error' => $error]);
    }

    public function save(): void
    {
        $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;
        abort_unless($church, 403);

        $manager = app(ChurchModuleManager::class);
        $data = $this->form->getState();
        $existing = $manager->settings($church, MessagingAvailability::MODULE_WHATSAPP);

        $token = filled($data['access_token'] ?? null)
            ? $manager->encryptSecret($data['access_token'])
            : ($existing['access_token'] ?? '');

        $manager->updateSettings($church, MessagingAvailability::MODULE_WHATSAPP, [
            'api_version' => $data['api_version'] ?? 'v21.0',
            'access_token' => $token,
            'phone_number_id' => $data['phone_number_id'] ?? '',
            'business_account_id' => $data['business_account_id'] ?? '',
            'confirmation_whatsapp_template' => $data['confirmation_whatsapp_template'] ?? '',
        ]);

        $manager->enable($church, MessagingAvailability::MODULE_WHATSAPP);

        Notification::make()
            ->title(__('WhatsApp settings saved'))
            ->success()
            ->send();

        $this->form->fill([
            ...$data,
            'access_token' => '',
        ]);
    }
}
