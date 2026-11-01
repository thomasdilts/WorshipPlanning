<?php

namespace App\Filament\Forms;

use App\Enums\MessagePreference;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * The person's own ntfy topic, shown and required only while ntfy is the preferred messaging.
 * Anyone who knows a topic on ntfy.sh can read it, so it must be long and hard to guess.
 */
class NtfyTopicField
{
    public const MIN_LENGTH = 12;

    public static function make(): TextInput
    {
        return TextInput::make('ntfy_topic')
            ->label(__('ntfy topic'))
            ->visible(fn (Get $get): bool => self::prefersNtfy($get('message_preference')))
            ->required(fn (Get $get): bool => self::prefersNtfy($get('message_preference')))
            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
            ->regex('/^\s*[-_A-Za-z0-9]{'.self::MIN_LENGTH.',64}\s*$/')
            ->validationMessages(['regex' => __('Use :min–64 letters, digits, dashes or underscores.', ['min' => self::MIN_LENGTH])])
            ->maxLength(70)
            ->suffixAction(Action::make('generateNtfyTopic')
                ->icon(Heroicon::OutlinedArrowPath)
                ->tooltip(__('Make a random topic'))
                ->action(fn (Set $set) => $set('ntfy_topic', 'worship-'.Str::lower(Str::random(20)))))
            ->helperText(__('Subscribe to this topic in the ntfy app. Anyone who knows the topic can read the messages, so keep it hard to guess — use the button to make one.'));
    }

    protected static function prefersNtfy(mixed $preference): bool
    {
        $preference = $preference instanceof MessagePreference ? $preference : MessagePreference::tryFrom((string) $preference);

        return $preference === MessagePreference::Ntfy;
    }
}
