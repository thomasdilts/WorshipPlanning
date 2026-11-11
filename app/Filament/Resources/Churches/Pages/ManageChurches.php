<?php

namespace App\Filament\Resources\Churches\Pages;

use App\Filament\Resources\Churches\ChurchResource;
use App\Models\Church;
use App\Models\Language;
use App\Models\User;
use App\Services\ChurchTransfer\ChurchImporter;
use App\Support\SeedsDefaultTeamTypes;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

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
            $this->importAction(),
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

    /**
     * Load a church export as a new church. Only for whoever runs the server (church create
     * permission), never in the demo.
     */
    protected function importAction(): Action
    {
        return Action::make('import')
            ->label(__('Import church'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->visible(fn (): bool => ! config('demo.enabled') && ChurchResource::canCreate())
            ->modalHeading(__('Import a church'))
            ->modalDescription(__('Creates a new, separate church from an export file, with all its people, services and files. Existing churches are never changed. Only import files from someone you trust: everyone in the file can sign in to this server with their old password.'))
            ->modalSubmitActionLabel(__('Import church'))
            ->schema([
                FileUpload::make('export')
                    ->label(__('Export file (.zip)'))
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                    ->disk('local')
                    ->directory('church-imports')
                    ->required()
                    ->helperText(__('A large file may be more than this server accepts for uploads. Then copy it to the server and run php artisan church:import with the file’s path.')),
                Checkbox::make('understood')
                    ->label(__('I understand that this adds a new church with real people’s data to this server'))
                    ->accepted()
                    ->validationMessages(['accepted' => __('Confirm before importing.')]),
            ])
            ->action(function (array $data): void {
                abort_if(config('demo.enabled') || ! ChurchResource::canCreate(), 403);

                $relative = (string) $data['export'];

                try {
                    set_time_limit(0);
                    $result = app(ChurchImporter::class)->import(Storage::disk('local')->path($relative));
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title(__('Import failed'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                } finally {
                    Storage::disk('local')->delete($relative);
                }

                Notification::make()
                    ->title(__('Church imported'))
                    ->body(__(':church was added with :files files. Its admins sign in with their usual email and password.', [
                        'church' => $result['church']->name,
                        'files' => $result['files'],
                    ]))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }
}
