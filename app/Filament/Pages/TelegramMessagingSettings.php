<?php

namespace App\Filament\Pages;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Tenancy\CurrentChurch;
use App\Enums\PasswordLinkKind;
use App\Models\Church;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Modules\Messaging\Services\TemplateMessages;
use Modules\Messaging\Support\MessagingAvailability;
use Modules\Messaging\Support\TelegramBotApi;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

class TelegramMessagingSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Admin');
    }

    protected static ?int $navigationSort = 59;

    public static function getNavigationLabel(): string
    {
        return __('Telegram settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Telegram messaging settings');
    }

    protected static ?string $slug = 'telegram-messaging-settings';

    protected string $view = 'filament-panels::pages.page';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->church_id === null || $user->church === null) {
            return false;
        }

        if (! app(ChurchModuleManager::class)->isEnabled($user->church, MessagingAvailability::MODULE_TELEGRAM)) {
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
        $settings = app(ChurchModuleManager::class)->settings($this->church(), MessagingAvailability::MODULE_TELEGRAM);

        $this->form->fill([
            'bot_token' => '',
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
                Callout::make(__('How to set up Telegram'))
                    ->info()
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->description(fn (): HtmlString => $this->setupSteps()),
                Callout::make(fn (): string => __('Connected to the bot @:bot', ['bot' => $this->botUsername()]))
                    ->success()
                    ->description(fn (): string => trans_choice('{0} Nobody has connected Telegram yet.|{1} 1 person has connected Telegram.|[2,*] :count people have connected Telegram.', $this->connectedCount()))
                    ->actions([
                        Action::make('openBot')
                            ->label(__('Open in Telegram'))
                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                            ->color('gray')
                            ->url(fn (): string => 'https://t.me/'.$this->botUsername(), shouldOpenInNewTab: true),
                    ])
                    ->visible(fn (): bool => $this->botUsername() !== null),
                Section::make(__('Telegram bot'))
                    ->schema([
                        TextInput::make('bot_token')
                            ->label(__('Bot token'))
                            ->password()
                            ->revealable()
                            ->required(fn (): bool => $this->existingToken() === null)
                            ->regex('/^\d+:[A-Za-z0-9_-]{30,}$/')
                            ->validationMessages(['regex' => __('Paste the whole token from BotFather, e.g. 123456789:AAH…')])
                            ->helperText(__('From BotFather. Leave blank to keep the current bot. Switching to another bot disconnects everyone, and they must connect again.')),
                    ]),
                Section::make(__('Preferred messaging confirmation'))
                    ->description(__('Sent by Telegram when someone chooses Telegram as their preferred messaging. The choice is refused if it cannot be sent.'))
                    ->schema([
                        Select::make('confirmation_template_id')
                            ->label(__('Message template'))
                            ->options(fn (?string $state): array => app(TemplateMessages::class)->confirmationOptions($state))
                            ->helperText(__('Templates marked “Other” are listed here.'))
                            ->searchable()
                            ->required(),
                    ]),
                Section::make(__('Password links'))
                    ->description(__('Sent by Telegram to people who prefer Telegram, to set a first password (invitation) and to reset a forgotten one. Without a template they get the email instead.'))
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
        $church = $this->church();
        $manager = app(ChurchModuleManager::class);
        $api = app(TelegramBotApi::class);
        $data = $this->form->getState();
        $settings = $manager->settings($church, MessagingAvailability::MODULE_TELEGRAM);
        $newToken = filled($data['bot_token'] ?? null) ? trim((string) $data['bot_token']) : null;
        $token = $newToken ?? (string) $api->botToken($church);
        $username = (string) ($settings['bot_username'] ?? '');

        if ($newToken !== null) {
            $bot = $this->fetchBotUsername($newToken);

            if ($bot === null) {
                return;
            }

            if ($username !== '' && strcasecmp($username, $bot) !== 0) {
                User::query()->where('church_id', $church->getKey())
                    ->update(['telegram_chat_id' => null, 'telegram_username' => null]);
            }

            $username = $bot;
        }

        $secret = filled($settings['webhook_secret'] ?? null) ? (string) $settings['webhook_secret'] : Str::random(48);

        $manager->updateSettings($church, MessagingAvailability::MODULE_TELEGRAM, [
            'bot_token' => $newToken !== null ? $manager->encryptSecret($newToken) : (string) ($settings['bot_token'] ?? ''),
            'bot_username' => $username,
            'webhook_secret' => $secret,
            'confirmation_template_id' => (int) $data['confirmation_template_id'],
            ...collect(PasswordLinkKind::cases())->mapWithKeys(fn (PasswordLinkKind $kind): array => [
                $kind->templateSettingKey() => filled($data[$kind->templateSettingKey()] ?? null) ? (int) $data[$kind->templateSettingKey()] : null,
            ])->all(),
        ]);

        $manager->enable($church, MessagingAvailability::MODULE_TELEGRAM);

        $webhookError = $this->registerWebhook($church, $token, $secret);

        if ($webhookError !== null) {
            Notification::make()
                ->title(__('Settings saved, but Telegram could not be told where to send replies'))
                ->body(__('People cannot connect until this works. The app must be reachable on https. :error', ['error' => $webhookError]))
                ->warning()
                ->persistent()
                ->send();
        } else {
            Notification::make()
                ->title(__('Telegram settings saved'))
                ->success()
                ->send();
        }

        $this->form->fill([
            ...$data,
            'bot_token' => '',
        ]);
    }

    /**
     * Checks the token with getMe; shows an error and returns null when Telegram does not accept it.
     */
    protected function fetchBotUsername(string $token): ?string
    {
        try {
            $response = app(TelegramBotApi::class)->call($token, 'getMe');
            $username = $response->json('result.username');

            if ($response->successful() && $response->json('ok') === true && filled($username)) {
                return (string) $username;
            }

            $error = TelegramBotApi::errorFrom($response);
        } catch (\Throwable $e) {
            $error = TelegramBotApi::redact($e->getMessage(), $token);
        }

        Notification::make()
            ->title(__('Telegram did not accept the bot token'))
            ->body($error)
            ->danger()
            ->persistent()
            ->send();

        return null;
    }

    /**
     * Points the bot's updates (people pressing Start, blocking the bot) at this church's webhook.
     */
    protected function registerWebhook(Church $church, string $token, string $secret): ?string
    {
        try {
            $response = app(TelegramBotApi::class)->call($token, 'setWebhook', [
                'url' => route('telegram.webhook', ['church' => $church->getKey()]),
                'secret_token' => $secret,
                'allowed_updates' => ['message', 'my_chat_member'],
            ]);

            return $response->successful() && $response->json('ok') === true ? null : TelegramBotApi::errorFrom($response);
        } catch (\Throwable $e) {
            return TelegramBotApi::redact($e->getMessage(), $token);
        }
    }

    protected function setupSteps(): HtmlString
    {
        $steps = [
            __('In Telegram, open :botfather and send /newbot.', ['botfather' => '<a href="https://t.me/BotFather" target="_blank" rel="noopener" class="underline">@BotFather</a>']),
            e(__('Give the bot your church’s name and a username ending in “bot”, e.g. MyChurchBot.')),
            e(__('Copy the token BotFather sends you, paste it below and save. The app connects the bot by itself.')),
            e(__('Each person chooses Telegram as preferred messaging in their profile and taps “Connect Telegram”.')),
        ];

        return new HtmlString(
            '<ol class="list-decimal ps-5 space-y-1">'.collect($steps)->map(fn (string $step): string => '<li>'.$step.'</li>')->implode('').'</ol>'
            .'<p class="mt-2">'.e(__('Telegram messages are free. Invitations get Yes / No buttons. In BotFather you can also add a picture (/setuserpic) and a description (/setdescription). Keep the token secret: anyone with it can send messages as your bot.')).'</p>'
        );
    }

    protected function church(): Church
    {
        $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;
        abort_unless($church, 403);

        return $church;
    }

    protected function botUsername(): ?string
    {
        return app(TelegramBotApi::class)->botUsername($this->church());
    }

    protected function connectedCount(): int
    {
        return User::query()->where('church_id', $this->church()->getKey())->whereNotNull('telegram_chat_id')->count();
    }

    /**
     * The saved (encrypted) bot token, if any.
     */
    protected function existingToken(): ?string
    {
        $token = app(ChurchModuleManager::class)->settings($this->church(), MessagingAvailability::MODULE_TELEGRAM)['bot_token'] ?? null;

        return filled($token) ? (string) $token : null;
    }
}
