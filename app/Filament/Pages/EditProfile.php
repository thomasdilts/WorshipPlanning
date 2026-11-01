<?php

namespace App\Filament\Pages;

use App\Enums\MessagePreference;
use App\Filament\Forms\NtfyTopicField;
use App\Models\Language;
use App\Models\User;
use App\Support\MessagePreferenceRule;
use App\Support\UserAvatar;
use Closure;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Modules\Abilities\Services\UserAbilities;
use Modules\Abilities\Support\AbilitiesForm;
use Modules\Messaging\Support\MessagingAvailability;

class EditProfile extends BaseEditProfile
{
    /** @var list<int>|null */
    protected ?array $pendingAbilityTemplateIds = null;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                UserAvatar::upload($this->getUser()->avatarDirectory()),
                $this->getNameFormComponent(),
                $this->getDisplayNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getLanguageFormComponent(),
                $this->getPhoneFormComponent(),
                $this->getMessagePreferenceFormComponent(),
                ...NtfyTopicField::components(),
                Section::make(__('Abilities'))
                    ->description(__('Task types you are approved to be assigned to as a person.'))
                    ->visible(fn (): bool => AbilitiesForm::profileVisible())
                    ->schema([
                        AbilitiesForm::profileSelect(),
                    ]),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['ability_template_ids'] = $this->getUser()->abilityTemplates()->pluck('task_templates.id')->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('ability_template_ids', $data)
            && (AbilitiesForm::canEditAbilities($this->getUser()))) {
            $this->pendingAbilityTemplateIds = array_map('intval', array_values($data['ability_template_ids'] ?? []));
        }

        unset($data['ability_template_ids']);

        return parent::mutateFormDataBeforeSave($data);
    }

    protected function afterSave(): void
    {
        if ($this->pendingAbilityTemplateIds !== null) {
            app(UserAbilities::class)->sync($this->getUser(), $this->pendingAbilityTemplateIds);
            $this->pendingAbilityTemplateIds = null;
        }
    }

    protected function getLanguageFormComponent(): Component
    {
        return Select::make('language_id')
            ->label(__('app.language'))
            ->options(fn (): array => Language::query()
                ->active()
                ->ordered()
                ->get()
                ->mapWithKeys(fn (Language $language): array => [
                    $language->id => $language->label(),
                ])
                ->all())
            ->required()
            ->native(false)
            ->helperText(__('app.language_helper'));
    }

    protected function getPhoneFormComponent(): Component
    {
        $church = $this->getUser()->church;

        return TextInput::make('phone')
            ->label(__('Mobile phone'))
            ->tel()
            ->maxLength(32)
            ->visible(fn (): bool => app(MessagingAvailability::class)->needsPhone($church))
            ->helperText(__('Any format, e.g. 070-123 45 67. Must be a mobile number for SMS / WhatsApp.'));
    }

    protected function getMessagePreferenceFormComponent(): Component
    {
        $church = $this->getUser()->church;
        $availability = app(MessagingAvailability::class);

        return Select::make('message_preference')
            ->label(__('Preferred messaging'))
            ->options(fn (): array => $availability->preferenceOptions($church)
                ?: [MessagePreference::Email->value => MessagePreference::Email->label()])
            ->required(fn (): bool => $availability->shouldAskPreference($church))
            ->visible(fn (): bool => $availability->shouldAskPreference($church))
            ->native(false)
            ->live()
            ->afterStateUpdated(NtfyTopicField::generateWhenChosen())
            ->rule(fn (Get $get): Closure => MessagePreferenceRule::make(
                current: $this->getUser()->message_preference,
                recipient: fn (): User => (clone $this->getUser())->forceFill([
                    'display_name' => $get('display_name'),
                    'email' => $get('email'),
                    'phone' => $get('phone'),
                    'ntfy_topic' => filled($get('ntfy_topic')) ? trim((string) $get('ntfy_topic')) : null,
                ]),
                languageId: filled($get('language_id')) ? (int) $get('language_id') : null,
            ))
            ->helperText(__('A short test message is sent when you change this. SMS, WhatsApp and ntfy must arrive for the choice to be saved.'));
    }

    protected function getDisplayNameFormComponent(): Component
    {
        return TextInput::make('display_name')
            ->label(__('Display name'))
            ->maxLength(100)
            ->helperText(__('Shown to other church members on schedules and tasks.'));
    }

    protected function getEmailFormComponent(): Component
    {
        $user = $this->getUser();

        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/edit-profile.form.email.label'))
            ->email()
            ->required()
            ->maxLength(255)
            ->rule(Rule::unique('users', 'email')
                ->where(fn ($query) => $query->where('church_id', $user->church_id))
                ->ignore($user->getKey()))
            ->live(debounce: 500);
    }

    protected function getPasswordFormComponent(): Component
    {
        // Do not Hash::make here — User casts password as 'hashed'.
        return TextInput::make('password')
            ->label(__('filament-panels::auth/pages/edit-profile.form.password.label'))
            ->validationAttribute(__('filament-panels::auth/pages/edit-profile.form.password.validation_attribute'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->rule(Password::default())
            ->autocomplete('new-password')
            ->dehydrated(fn ($state): bool => filled($state))
            ->live(debounce: 500)
            ->same('passwordConfirmation');
    }
}
