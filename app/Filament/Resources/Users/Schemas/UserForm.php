<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\MessagePreference;
use App\Filament\Forms\NtfyTopicField;
use App\Filament\Forms\PreferenceTestMessageAction;
use App\Filament\Forms\TelegramConnectField;
use App\Models\Language;
use App\Models\Role;
use App\Models\User;
use App\Support\MessagePreferenceRule;
use App\Support\UserAvatar;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Modules\Abilities\Support\AbilitiesForm;
use Modules\Messaging\Support\ChurchMailer;
use Modules\Messaging\Support\MessagingAvailability;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                UserAvatar::upload('churches/shared/avatars')
                    ->directory(fn (?User $record): string => $record?->avatarDirectory()
                        ?? ('churches/'.(auth()->user()?->church_id ?? 'shared').'/avatars')),
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('display_name')
                    ->label(__('Display name'))
                    ->maxLength(100),
                TextInput::make('email')
                    ->label(__('Email'))
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->rule(function (?User $record) {
                        return Rule::unique('users', 'email')
                            ->where(fn ($query) => $query->where(
                                'church_id',
                                $record?->church_id ?? auth()->user()?->church_id
                            ))
                            ->ignore($record?->getKey());
                    })
                    ->disabled(fn (?User $record): bool => (bool) $record?->isDemoAccount())
                    ->helperText(fn (?User $record): ?string => $record?->isDemoAccount() ? __('Locked in demo') : null),
                Toggle::make('send_invite')
                    ->label(__('Email them a link to choose their own password'))
                    ->default(true)
                    ->live()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'create' && self::churchCanEmail())
                    ->helperText(__('Recommended. You can also set a password below; they can change it from the link.')),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->disabled(fn (?User $record): bool => (bool) $record?->isDemoAccount())
                    ->rule(Password::defaults())
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation, Get $get): bool => $operation === 'create'
                        && ! (self::churchCanEmail() && (bool) $get('send_invite')))
                    ->helperText(function (string $operation, ?User $record): string {
                        if ($record?->isDemoAccount()) {
                            return __('Locked in demo');
                        }

                        if ($operation === 'edit') {
                            return __('Leave blank to keep the current password.');
                        }

                        return self::churchCanEmail()
                            ? __('Optional when you send the email link. Required if you do not.')
                            : __('Required. Email is not set up for this church, so no invite can be sent — give them this password yourself.');
                    }),
                Select::make('language_id')
                    ->label(__('app.language'))
                    ->options(fn (): array => Language::query()
                        ->active()
                        ->ordered()
                        ->get()
                        ->mapWithKeys(fn (Language $language): array => [
                            $language->id => $language->label(),
                        ])
                        ->all())
                    ->default(fn (): ?int => Language::query()->where('iso', 'en')->value('id'))
                    ->required()
                    ->native(false)
                    ->helperText(__('app.language_helper')),
                TextInput::make('phone')
                    ->label(__('Mobile phone'))
                    ->tel()
                    ->maxLength(32)
                    ->visible(fn (): bool => app(MessagingAvailability::class)->needsPhone(auth()->user()?->church))
                    ->helperText(__('Any format, e.g. 070-123 45 67. Must be a mobile number for SMS / WhatsApp.')),
                Select::make('message_preference')
                    ->label(__('Preferred messaging'))
                    ->options(fn (): array => app(MessagingAvailability::class)->preferenceOptions(auth()->user()?->church)
                        ?: [MessagePreference::Email->value => MessagePreference::Email->label()])
                    ->default(MessagePreference::Email->value)
                    ->required(fn (): bool => app(MessagingAvailability::class)->shouldAskPreference(auth()->user()?->church))
                    ->visible(fn (): bool => app(MessagingAvailability::class)->shouldAskPreference(auth()->user()?->church))
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(NtfyTopicField::generateWhenChosen())
                    ->rule(fn (Get $get, ?User $record): Closure => MessagePreferenceRule::make(
                        current: $record?->message_preference,
                        recipient: fn (): User => self::recipientFromForm($get, $record),
                        languageId: filled($get('language_id')) ? (int) $get('language_id') : null,
                    ))
                    ->helperText(__('A short test message is sent when you change this. SMS, WhatsApp, ntfy and Telegram must arrive for the choice to be saved.')),
                PreferenceTestMessageAction::make(
                    recipient: fn (Get $get, ?User $record): User => self::recipientFromForm($get, $record),
                    visible: fn (): bool => app(MessagingAvailability::class)->shouldAskPreference(auth()->user()?->church),
                ),
                ...NtfyTopicField::components(),
                ...TelegramConnectField::components(person: fn (?User $record): ?User => $record, canConnect: false),
                Section::make(__('Roles'))
                    ->description(__('Choose one or more roles. Permissions for each role are configured under Administration → Roles.'))
                    ->schema([
                        Select::make('roles')
                            ->label(__('Assigned roles'))
                            ->multiple()
                            ->options(fn (): array => Role::query()
                                ->forCurrentChurch()
                                ->orderBy('name')
                                ->pluck('name', 'name')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->disabled(fn (?User $record): bool => (bool) $record?->isDemoLogin())
                            ->helperText(fn (?User $record): string => $record?->isDemoLogin()
                                ? __('Locked in demo')
                                : __('A person can have several roles; permissions are combined.')),
                    ]),
                AbilitiesForm::adminSection(),
            ]);
    }

    /**
     * Invites and other emails can only be sent when the church's email module has SMTP settings.
     */
    public static function churchCanEmail(): bool
    {
        return app(ChurchMailer::class)->isConfigured(auth()->user()?->church);
    }

    /**
     * The person as entered in the form, with the unsaved contact details a test message goes to.
     */
    protected static function recipientFromForm(Get $get, ?User $record): User
    {
        return ($record ? clone $record : new User(['church_id' => auth()->user()?->church_id]))
            ->forceFill([
                'name' => $get('name'),
                'display_name' => $get('display_name'),
                'email' => $get('email'),
                'phone' => $get('phone'),
                'ntfy_topic' => filled($get('ntfy_topic')) ? trim((string) $get('ntfy_topic')) : null,
                'telegram_chat_id' => TelegramConnectField::connection($record)['chat_id'] ?? null,
            ]);
    }
}
