<?php

namespace App\Filament\Pages;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\PasswordLinkKind;
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
use Modules\Messaging\Channels\NtfyChannel;
use Modules\Messaging\Services\TemplateMessages;
use Modules\Messaging\Support\MessagingAvailability;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

class NtfyMessagingSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Admin');
    }

    protected static ?int $navigationSort = 58;

    public static function getNavigationLabel(): string
    {
        return __('ntfy settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('ntfy messaging settings');
    }

    protected static ?string $slug = 'ntfy-messaging-settings';

    protected string $view = 'filament-panels::pages.page';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->church_id === null || $user->church === null) {
            return false;
        }

        if (! app(ChurchModuleManager::class)->isEnabled($user->church, MessagingAvailability::MODULE_NTFY)) {
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

        $settings = app(ChurchModuleManager::class)->settings($church, MessagingAvailability::MODULE_NTFY);

        $this->form->fill([
            'server_url' => $settings['server_url'] ?? NtfyChannel::DEFAULT_SERVER,
            'access_token' => '',
            'confirmation_template_id' => $settings['confirmation_template_id'] ?? null,
            ...collect(PasswordLinkKind::cases())->mapWithKeys(fn (PasswordLinkKind $kind): array => [
                $kind->templateSettingKey() => $settings[$kind->templateSettingKey()] ?? null,
            ])->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('ntfy server'))
                    ->description(__('ntfy.sh is free and needs no account. Each person chooses their own topic in their profile and subscribes to it in the ntfy app.'))
                    ->schema([
                        TextInput::make('server_url')
                            ->label(__('Server URL'))
                            ->url()
                            ->required()
                            ->default(NtfyChannel::DEFAULT_SERVER),
                        TextInput::make('access_token')
                            ->label(__('Access token (optional)'))
                            ->password()
                            ->revealable()
                            ->helperText(__('Only for your own server or reserved topics. Leave blank to keep the current token.')),
                    ]),
                Section::make(__('Preferred messaging confirmation'))
                    ->description(__('Sent by ntfy when someone chooses ntfy as their preferred messaging. The choice is refused if it cannot be sent.'))
                    ->schema([
                        Select::make('confirmation_template_id')
                            ->label(__('Message template'))
                            ->options(fn (?string $state): array => app(TemplateMessages::class)->confirmationOptions($state))
                            ->helperText(__('Templates marked “Other” are listed here.'))
                            ->searchable()
                            ->required(),
                    ]),
                Section::make(__('Password links'))
                    ->description(__('Sent by ntfy to people who prefer ntfy, to set a first password (invitation) and to reset a forgotten one. Without a template they get the email instead.'))
                    ->schema(collect(PasswordLinkKind::cases())->map(fn (PasswordLinkKind $kind): Select => Select::make($kind->templateSettingKey())
                        ->label($kind->label())
                        ->options(fn (?string $state): array => app(TemplateMessages::class)->confirmationOptions($state))
                        ->helperText(__('Templates marked “Other” are listed here. Use {{password_url}} for the link.'))
                        ->searchable())
                        ->all())
                    ->columns(2),
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

    public function save(): void
    {
        $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;
        abort_unless($church, 403);

        $manager = app(ChurchModuleManager::class);
        $data = $this->form->getState();
        $token = filled($data['access_token'] ?? null)
            ? $manager->encryptSecret($data['access_token'])
            : ($this->existingToken() ?? '');

        $manager->updateSettings($church, MessagingAvailability::MODULE_NTFY, [
            'server_url' => rtrim((string) $data['server_url'], '/'),
            'access_token' => $token,
            'confirmation_template_id' => (int) $data['confirmation_template_id'],
            ...collect(PasswordLinkKind::cases())->mapWithKeys(fn (PasswordLinkKind $kind): array => [
                $kind->templateSettingKey() => filled($data[$kind->templateSettingKey()] ?? null) ? (int) $data[$kind->templateSettingKey()] : null,
            ])->all(),
        ]);

        $manager->enable($church, MessagingAvailability::MODULE_NTFY);

        Notification::make()
            ->title(__('ntfy settings saved'))
            ->success()
            ->send();

        $this->form->fill([
            ...$data,
            'access_token' => '',
        ]);
    }

    /**
     * The saved (encrypted) access token, if any.
     */
    protected function existingToken(): ?string
    {
        $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;

        if ($church === null) {
            return null;
        }

        $token = app(ChurchModuleManager::class)->settings($church, MessagingAvailability::MODULE_NTFY)['access_token'] ?? null;

        return filled($token) ? (string) $token : null;
    }
}
