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
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->color('gray')
                        ->url(self::APP_STORE_URL, shouldOpenInNewTab: true),
                    Action::make('ntfyGooglePlay')
                        ->label(__('Android (Google Play)'))
                        ->icon(Heroicon::OutlinedArrowDownTray)
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
