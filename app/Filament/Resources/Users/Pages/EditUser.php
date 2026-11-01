<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\UserInviter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Modules\Abilities\Services\UserAbilities;
use Modules\Abilities\Support\AbilitiesForm;
use Spatie\Permission\PermissionRegistrar;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var list<string> */
    protected array $pendingRoles = [];

    /** @var list<int>|null */
    protected ?array $pendingAbilityTemplateIds = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resendInvite')
                ->label(__('Send password invite'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('Send password invite?'))
                ->modalDescription(__('They get an email to choose or reset their password. Limited to one send per minute.'))
                ->visible(fn (): bool => filled($this->getRecord()->email)
                    && app(UserInviter::class)->canInvite($this->getRecord()->loadMissing('church')))
                ->action(function (): void {
                    try {
                        app(UserInviter::class)->send(
                            $this->getRecord()->loadMissing('church'),
                            auth()->user(),
                        );

                        Notification::make()
                            ->title(__('Invite sent'))
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title(__('Could not send invite'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->record->church_id);

        $data['roles'] = $this->record->getRoleNames()->all();
        $data['ability_template_ids'] = $this->record->abilityTemplates()->pluck('task_templates.id')->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingRoles = array_values($data['roles'] ?? []);
        unset($data['roles']);

        if (array_key_exists('ability_template_ids', $data) && AbilitiesForm::canEditAbilities($this->record)) {
            $this->pendingAbilityTemplateIds = array_map('intval', array_values($data['ability_template_ids'] ?? []));
        }

        unset($data['ability_template_ids']);

        return $data;
    }

    protected function afterSave(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->record->church_id);
        $this->record->syncRoles($this->pendingRoles);

        if ($this->pendingAbilityTemplateIds !== null) {
            app(UserAbilities::class)->sync($this->record, $this->pendingAbilityTemplateIds);
        }
    }
}
