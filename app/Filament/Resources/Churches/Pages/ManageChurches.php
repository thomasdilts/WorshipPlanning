<?php

namespace App\Filament\Resources\Churches\Pages;

use App\Filament\Resources\Churches\ChurchResource;
use App\Models\Church;
use App\Models\Language;
use App\Models\User;
use App\Support\SeedsDefaultTeamTypes;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class ManageChurches extends ManageRecords
{
    protected static string $resource = ChurchResource::class;

    protected ?string $pendingAdminName = null;

    protected ?string $pendingAdminEmail = null;

    protected ?string $pendingAdminPassword = null;

    public function getTitle(): string|Htmlable
    {
        return __('Churches');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Create a church, optionally with its first admin, then they finish setup in the app.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $this->pendingAdminName = filled($data['admin_name'] ?? null)
                        ? trim((string) $data['admin_name'])
                        : null;
                    $this->pendingAdminEmail = filled($data['admin_email'] ?? null)
                        ? trim((string) $data['admin_email'])
                        : null;

                    $this->pendingAdminPassword = filled($data['admin_password'] ?? null)
                        ? (string) $data['admin_password']
                        : null;

                    unset($data['admin_name'], $data['admin_email'], $data['admin_password']);

                    return $data;
                })
                ->after(function (Church $record): void {
                    RolesAndPermissionsSeeder::seedForChurch($record);
                    SeedsDefaultTeamTypes::for($record);

                    $adminEmail = $this->pendingAdminEmail;
                    $adminName = $this->pendingAdminName;
                    $adminPassword = $this->pendingAdminPassword;
                    $this->pendingAdminEmail = null;
                    $this->pendingAdminName = null;
                    $this->pendingAdminPassword = null;

                    if (! filled($adminEmail) || ! filled($adminPassword)) {
                        return;
                    }

                    if (! filled($adminName)) {
                        $adminName = Str::before($adminEmail, '@') ?: 'Admin';
                    }

                    $admin = User::query()->create([
                        'church_id' => $record->id,
                        'name' => $adminName,
                        'display_name' => $adminName,
                        'email' => $adminEmail,
                        'password' => $adminPassword,
                        'language_id' => Language::query()->where('iso', $record->locale ?: 'en')->value('id')
                            ?? Language::query()->where('iso', 'en')->value('id'),
                    ]);

                    app(PermissionRegistrar::class)->setPermissionsTeamId($record->id);
                    $admin->syncRoles(['Church Admin']);

                    Notification::make()
                        ->title(__('Church created with its first admin'))
                        ->body(__('Give the admin their password yourself — no email was sent.'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
