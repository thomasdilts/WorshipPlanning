<?php

namespace App\Filament\Forms;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\MessagePreference;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Modules\Messaging\Channels\NtfyChannel;
use Modules\Messaging\Support\MessagingAvailability;

/**
 * The person's ntfy topic. Anyone who knows a topic can read it, so the app always makes a long random
 * one when ntfy is chosen; people only copy it into the ntfy app and cannot type their own.
 */
class NtfyTopicField
{
    public const PREFIX = 'worship-';

    public const RANDOM_LENGTH = 20;

    public const APP_STORE_URL = 'https://apps.apple.com/app/ntfy/id1625396347';

    public const GOOGLE_PLAY_URL = 'https://play.google.com/store/apps/details?id=io.heckel.ntfy';

    /** Apple logo from Simple Icons (CC0), 24×24 view box. */
    protected const APPLE_LOGO_PATH = 'M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701';

    /** Android logo from Simple Icons (CC0), 24×24 view box. */
    protected const ANDROID_LOGO_PATH = 'M18.4395 5.5586c-.675 1.1664-1.352 2.3318-2.0274 3.498-.0366-.0155-.0742-.0286-.1113-.043-1.8249-.6957-3.484-.8-4.42-.787-1.8551.0185-3.3544.4643-4.2597.8203-.084-.1494-1.7526-3.021-2.0215-3.4864a1.1451 1.1451 0 0 0-.1406-.1914c-.3312-.364-.9054-.4859-1.379-.203-.475.282-.7136.9361-.3886 1.5019 1.9466 3.3696-.0966-.2158 1.9473 3.3593.0172.031-.4946.2642-1.3926 1.0177C2.8987 12.176.452 14.772 0 18.9902h24c-.119-1.1108-.3686-2.099-.7461-3.0683-.7438-1.9118-1.8435-3.2928-2.7402-4.1836a12.1048 12.1048 0 0 0-2.1309-1.6875c.6594-1.122 1.312-2.2559 1.9649-3.3848.2077-.3615.1886-.7956-.0079-1.1191a1.1001 1.1001 0 0 0-.8515-.5332c-.5225-.0536-.9392.3128-1.0488.5449zm-.0391 8.461c.3944.5926.324 1.3306-.1563 1.6503-.4799.3197-1.188.0985-1.582-.4941-.3944-.5927-.324-1.3307.1563-1.6504.4727-.315 1.1812-.1086 1.582.4941zM7.207 13.5273c.4803.3197.5506 1.0577.1563 1.6504-.394.5926-1.1038.8138-1.584.4941-.48-.3197-.5503-1.0577-.1563-1.6504.4008-.6021 1.1087-.8106 1.584-.4941z';

    /**
     * Links to install the ntfy app, the warning on which server to use in it, and the topic field.
     *
     * @return array{0: Callout, 1: Callout, 2: TextInput}
     */
    public static function components(): array
    {
        return [
            Callout::make(__('Get the ntfy app'))
                ->info()
                ->icon(Heroicon::OutlinedDevicePhoneMobile)
                ->description(__('Install the free ntfy app on your phone to receive the messages.'))
                ->actions([
                    Action::make('ntfyAppStore')
                        ->label(__('iPhone (App Store)'))
                        ->icon(self::logo(self::APPLE_LOGO_PATH, 'currentColor'))
                        ->color('gray')
                        ->url(self::APP_STORE_URL, shouldOpenInNewTab: true),
                    Action::make('ntfyGooglePlay')
                        ->label(__('Android (Google Play)'))
                        ->icon(self::logo(self::ANDROID_LOGO_PATH, 'currentColor'))
                        ->color('gray')
                        ->url(self::GOOGLE_PLAY_URL, shouldOpenInNewTab: true),
                ])
                ->visible(fn (Get $get): bool => self::prefersNtfy($get('message_preference'))),
            Callout::make(__('Set the server in the ntfy app'))
                ->warning()
                ->description(fn (): HtmlString => self::serverInstructions())
                ->visible(fn (Get $get): bool => self::prefersNtfy($get('message_preference'))),
            self::make(),
        ];
    }

    public static function make(): TextInput
    {
        return TextInput::make('ntfy_topic')
            ->label(__('ntfy topic'))
            ->visible(fn (Get $get): bool => self::prefersNtfy($get('message_preference')))
            ->required(fn (Get $get): bool => self::prefersNtfy($get('message_preference')))
            ->readOnly()
            ->regex('/^'.preg_quote(self::PREFIX, '/').'[a-z0-9]{'.self::RANDOM_LENGTH.'}$/')
            ->validationMessages(['regex' => __('Make a new topic with the button next to the field.')])
            ->unique(ignoreRecord: true)
            ->copyable(copyMessage: __('Copied'))
            ->suffixAction(Action::make('generateNtfyTopic')
                ->icon(Heroicon::OutlinedArrowPath)
                ->tooltip(__('Make a new topic'))
                ->action(fn (Set $set) => $set('ntfy_topic', self::generate())))
            ->helperText(__('Copy this topic into the ntfy app. Keep it private: anyone who knows it can read the messages. Make a new one if it has been shared.'));
    }

    /**
     * For the "preferred messaging" select: gives the person a topic as soon as ntfy is chosen.
     */
    public static function generateWhenChosen(): Closure
    {
        return function (mixed $state, Get $get, Set $set): void {
            if (self::prefersNtfy($state) && blank($get('ntfy_topic'))) {
                $set('ntfy_topic', self::generate());
            }
        };
    }

    public static function generate(): string
    {
        return self::PREFIX.Str::lower(Str::random(self::RANDOM_LENGTH));
    }

    protected static function logo(string $path, string $color): HtmlString
    {
        return new HtmlString('<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width:100%;height:100%" fill="'.e($color).'"><path d="'.e($path).'"/></svg>');
    }

    protected static function prefersNtfy(mixed $preference): bool
    {
        $preference = $preference instanceof MessagePreference ? $preference : MessagePreference::tryFrom((string) $preference);

        return $preference === MessagePreference::Ntfy;
    }

    /**
     * The app subscribes on ntfy.sh unless told otherwise, so a church server must be entered by hand.
     */
    protected static function serverInstructions(): HtmlString
    {
        $server = self::serverUrl();

        if (rtrim($server, '/') === NtfyChannel::DEFAULT_SERVER) {
            return new HtmlString(e(__('In the ntfy app, subscribe to the topic below on the default server, ntfy.sh.')));
        }

        return new HtmlString(__('When you subscribe to the topic below in the ntfy app, turn on “Use another server” and enter :server. Without it the app listens on ntfy.sh and no messages arrive.', [
            'server' => '<strong>'.e($server).'</strong>',
        ]));
    }

    protected static function serverUrl(): string
    {
        $church = auth()->user() instanceof User ? auth()->user()->church : null;

        if ($church === null) {
            return NtfyChannel::DEFAULT_SERVER;
        }

        $settings = app(ChurchModuleManager::class)->settings($church, MessagingAvailability::MODULE_NTFY);

        return (string) ($settings['server_url'] ?? NtfyChannel::DEFAULT_SERVER);
    }
}
