<?php

namespace App\Filament\Pages;

use App\Core\Tenancy\CurrentChurch;
use App\Models\Church;
use App\Models\User;
use App\Services\ChurchTransfer\ChurchExporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

/**
 * Lets a church admin download the whole church as one file, for a backup or a move to another
 * server. The page explains what the file holds and what it leaves out before anyone downloads it.
 */
class ExportChurchData extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Admin');
    }

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'export-church-data';

    public static function getNavigationLabel(): string
    {
        return __('Export church data');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Export church data');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Download everything about your church in one file, to keep as a backup or to move your church to another WorshipPlanning server.');
    }

    protected string $view = 'filament-panels::pages.page';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (config('demo.enabled') || ! $user instanceof User || $user->church_id === null || $user->church === null) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('church.export');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill(['include_secrets' => false, 'understood' => false]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make(__('This file holds everyone’s personal data'))
                    ->warning()
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->description(__('Names, email addresses, phone numbers and the scrambled passwords of everyone in your church. Store it as carefully as a membership register: never email it or put it in a shared folder, and delete it when you no longer need it.')),
                Section::make(__('What the export contains'))
                    ->schema([
                        UnorderedList::make([
                            Text::make(__('Everyone in the church with their contact details, roles, teams, abilities, language and profile picture.')),
                            Text::make(__('People’s passwords in scrambled form, so they can sign in with the same password after a move. Nobody, including you, can read them.')),
                            Text::make(__('All services and service orders, past and future, with who was assigned, every answer and the invitations that were sent.')),
                            Text::make(__('Teams, task templates, songs, message templates and unavailability.')),
                            Text::make(__('Uploaded files: pictures, shared files, profile pictures and the church logo.')),
                            Text::make(__('Which modules are turned on, with their settings.')),
                        ]),
                    ]),
                Section::make(__('What the export leaves out'))
                    ->schema([
                        UnorderedList::make([
                            Text::make(__('Passwords and tokens for email, SMS, WhatsApp, ntfy, Telegram and the Bible API, unless you include them below. Without them, someone enters them again after a move.')),
                            Text::make(__('Server administrator accounts, and everything that belongs to other churches on this server.')),
                            Text::make(__('Who is signed in right now, password reset links, and messages still waiting to be sent.')),
                            Text::make(__('The accept and decline links in messages already sent. After a move those links stop working, and people answer in the app instead.')),
                            Text::make(__('Anything changed after you download the file. The export is a snapshot of this moment.')),
                        ]),
                    ]),
                Section::make(__('How importing works'))
                    ->schema([
                        UnorderedList::make([
                            Text::make(__('Importing is done by whoever runs the server you move to: a server administrator in the app, or with the command php artisan church:import on the server.')),
                            Text::make(__('An import always creates a new, separate church. It never changes or replaces a church that already exists, so importing the same file twice gives two copies.')),
                            Text::make(__('The file can be imported into the same or a newer version of WorshipPlanning, not an older one.')),
                            Text::make(__('Everything stays connected: assignments, teams and answers point at the right people afterwards.')),
                        ]),
                    ])
                    ->collapsible(),
                Section::make(__('Moving your church to another server'))
                    ->schema([
                        Text::make(__('1. Pick a moment when nobody is planning, so nothing changes between the export and the move.')),
                        Text::make(__('2. Download the export, with passwords and tokens if messaging should keep working without setting it up again.')),
                        Text::make(__('3. Hand the file to the administrator of the new server in a safe way, and have them import it.')),
                        Text::make(__('4. On the new server, open the Telegram settings and press Save, so Telegram sends replies to the new address. Then check that each kind of messaging you use still arrives.')),
                        Text::make(__('5. Tell everyone the new web address. They sign in with the same email and password as before.')),
                        Text::make(__('6. Delete the export file. Delete the church on the old server once you are sure everything arrived.')),

                    ])
                    ->collapsible(),
                Section::make(__('Download'))
                    ->description(__('A church with many pictures and files gives a large file and can take a minute. If the download fails, the server administrator can make the export with php artisan church:export.'))
                    ->schema([
                        Toggle::make('include_secrets')
                            ->label(__('Include passwords and tokens for messaging and the Bible API'))
                            ->helperText(__('Anyone who gets the file can then send email, SMS and messages in your church’s name and run up costs on paid services. Leave this off for a backup; turn it on only when moving.')),
                        Checkbox::make('understood')
                            ->label(__('I understand that this file contains personal data and I will keep it safe'))
                            ->accepted()
                            ->validationMessages(['accepted' => __('Confirm that you will keep the file safe.')]),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('download')
                    ->footer([
                        Actions::make([
                            Action::make('download')
                                ->label(__('Download export'))
                                ->icon(Heroicon::OutlinedArrowDownTray)
                                ->submit('download'),
                        ]),
                    ]),
            ]);
    }

    /**
     * Build the file, then send the browser to a short-lived link for it. Returning the file from
     * Livewire would squeeze the whole zip through one JSON response.
     */
    public function download(): void
    {
        abort_unless(static::canAccess(), 403);

        $church = $this->church();
        $data = $this->form->getState();

        set_time_limit(0);

        if (! is_dir(static::exportDirectory())) {
            mkdir(static::exportDirectory(), 0770, true);
        }

        $export = $church->id.'-'.Str::uuid()->toString();
        app(ChurchExporter::class)->export($church, static::exportDirectory()."/{$export}.zip", (bool) ($data['include_secrets'] ?? false));

        $this->redirect(URL::temporarySignedRoute('church-export.download', now()->addMinutes(30), ['export' => $export]));
    }

    /**
     * Finished exports wait here until downloaded; the scheduler removes any left behind.
     */
    public static function exportDirectory(): string
    {
        return storage_path('app/private/church-exports');
    }

    protected function church(): Church
    {
        $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;
        abort_unless($church instanceof Church, 403);

        return $church;
    }
}
