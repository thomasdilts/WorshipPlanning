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
use Modules\Messaging\Services\TemplateMessages;
use Modules\Messaging\Support\MessagingAvailability;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

class MessagingSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Admin');
    }

    protected static ?int $navigationSort = 55;

    public static function getNavigationLabel(): string
    {
        return __('Email settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Email messaging settings');
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

        if (! app(ChurchModuleManager::class)->isEnabled($user->church, MessagingAvailability::MODULE_EMAIL)) {
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

        $settings = app(ChurchModuleManager::class)->settings($church, MessagingAvailability::MODULE_EMAIL);
        $email = $settings['email'] ?? [];

        $this->form->fill([
            'host' => $email['host'] ?? '',
            'port' => $email['port'] ?? 587,
            'username' => $email['username'] ?? '',
            'password' => '',
            'encryption' => $email['encryption'] ?? 'tls',
            'from_address' => $email['from_address'] ?? '',
            'from_name' => $email['from_name'] ?? '',
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
                Section::make(__('Email (SMTP)'))
                    ->description(__('How WorshipPlanning sends emails for this church.'))
                    ->schema([
                        TextInput::make('host')->label(__('SMTP host'))->required(),
                        TextInput::make('port')->numeric()->default(587)->required(),
                        Select::make('encryption')
                            ->options([
                                'tls' => 'TLS',
                                'ssl' => 'SSL',
                                '' => 'None',
                            ])
                            ->native(false),
                        TextInput::make('username'),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->helperText(__('Leave blank to keep the current password.')),
                        TextInput::make('from_address')->email()->required()->label(__('From address')),
                        TextInput::make('from_name')->label(__('From name')),
                    ])
                    ->columns(2),
                Section::make(__('Preferred messaging confirmation'))
                    ->description(__('Sent by email when someone chooses email as their preferred messaging.'))
                    ->schema([
                        Select::make('confirmation_template_id')
                            ->label(__('Message template'))
                            ->options(fn (?string $state): array => app(TemplateMessages::class)->confirmationOptions($state))
                            ->helperText(__('Templates marked “Other” are listed here.'))
                            ->searchable()
                            ->required(),
                    ]),
                Section::make(__('Password links'))
                    ->description(__('Sent by email to set a first password (invitation) and to reset a forgotten one. Without a template the built-in email is sent.'))
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
        $existing = $manager->settings($church, MessagingAvailability::MODULE_EMAIL);
        $existingPassword = $existing['email']['password'] ?? '';

        $password = filled($data['password'] ?? null)
            ? $manager->encryptSecret($data['password'])
            : $existingPassword;

        $manager->updateSettings($church, MessagingAvailability::MODULE_EMAIL, [
            'email' => [
                'host' => $data['host'] ?? '',
                'port' => (int) ($data['port'] ?? 587),
                'username' => $data['username'] ?? '',
                'password' => $password,
                'encryption' => $data['encryption'] ?? 'tls',
                'from_address' => $data['from_address'] ?? '',
                'from_name' => $data['from_name'] ?? '',
            ],
            'confirmation_template_id' => (int) $data['confirmation_template_id'],
            ...collect(PasswordLinkKind::cases())->mapWithKeys(fn (PasswordLinkKind $kind): array => [
                $kind->templateSettingKey() => filled($data[$kind->templateSettingKey()] ?? null) ? (int) $data[$kind->templateSettingKey()] : null,
            ])->all(),
        ]);

        $manager->enable($church, MessagingAvailability::MODULE_EMAIL);

        Notification::make()
            ->title(__('Messaging settings saved'))
            ->success()
            ->send();

        $this->form->fill([
            ...$data,
            'password' => '',
        ]);
    }
}
