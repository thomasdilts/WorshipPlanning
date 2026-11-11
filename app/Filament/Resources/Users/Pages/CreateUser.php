<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\UserResource;
use App\Models\Role;
use App\Services\UserInviter;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Abilities\Services\UserAbilities;
use Modules\Abilities\Support\AbilitiesForm;
use Spatie\Permission\PermissionRegistrar;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** @var list<string> */
    protected array $pendingRoles = [];

    /** @var list<int>|null */
    protected ?array $pendingAbilityTemplateIds = null;

    protected bool $shouldSendInvite = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        /* Without roles.manage a new person can only start as a Member. */
        $this->pendingRoles = UserForm::canAssignRoles()
            ? array_values($data['roles'] ?? [])
            : Role::query()->forCurrentChurch()->where('name', 'Member')->pluck('name')->all();
        unset($data['roles']);

        if (array_key_exists('ability_template_ids', $data) && AbilitiesForm::canEditAbilities(null)) {
            $this->pendingAbilityTemplateIds = array_map('intval', array_values($data['ability_template_ids'] ?? []));
        }

        unset($data['ability_template_ids']);

        $this->shouldSendInvite = UserForm::churchCanEmail() && (bool) ($this->data['send_invite'] ?? true);

        if (blank($data['password'] ?? null)) {
            $data['password'] = app(UserInviter::class)->randomPassword();
        }

        $data['church_id'] = auth()->user()?->church_id;

        return $data;
    }

    protected function afterCreate(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->record->church_id);
        $this->record->syncRoles($this->pendingRoles);

        if ($this->pendingAbilityTemplateIds !== null) {
            app(UserAbilities::class)->sync($this->record, $this->pendingAbilityTemplateIds);
        }

        if (! $this->shouldSendInvite) {
            return;
        }

        try {
            app(UserInviter::class)->send($this->record->loadMissing('church'), auth()->user());

            Notification::make()
                ->title(__('Invite sent'))
                ->body(__('They can choose their own password from the email link.'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Person saved, but the invite email failed'))
                ->body($e->getMessage())
                ->warning()
                ->send();
        }
    }

    public function getTitle(): string|Htmlable
    {
        return __('Invite person');
    }
}
