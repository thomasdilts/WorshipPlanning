<?php

namespace App\Filament\Pages;

use App\Core\Tenancy\CurrentChurch;
use App\Models\Church;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

class ChurchSettings extends Page
{
    /** @var array<string, string> */
    protected array $extraBodyAttributes = [
        'class' => 'wp-church-settings',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Admin');
    }

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('Church');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Church settings');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Name and logo for the top bar and PDF exports. This is how volunteers recognize your church in the app.');
    }

    protected string $view = 'filament-panels::pages.page';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->church_id === null || $user->church === null) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('church.update');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $church = $this->church();
        abort_unless($church, 403);

        $this->form->fill([
            'name' => $church->name,
            'logo_path' => $church->logo_path,
            'uses_24_hour_time' => $church->uses_24_hour_time,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $churchId = auth()->user()?->church_id ?? 0;

        return $schema
            ->components([
                Section::make(__('Identity'))
                    ->description(__('Keep the mark quiet: dark artwork on a transparent PNG works best.'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Church name'))
                            ->required()
                            ->maxLength(120),
                        FileUpload::make('logo_path')
                            ->label(__('Logo'))
                            ->image()
                            ->disk('public')
                            ->directory('churches/'.$churchId.'/logo')
                            ->visibility('public')
                            ->imageEditor()
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth(400)
                            ->imageResizeTargetHeight(160)
                            ->maxSize(2048)
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/gif'])
                            ->extraAttributes(['class' => 'wp-logo-upload'])
                            // The page has no record, so name the one existing path that may be kept.
                            ->preventFilePathTampering(allowFilePathUsing: fn (string $file): bool => $file === $this->church()?->logo_path)
                            ->helperText(__('Transparent PNG preferred. Dark mode sits the mark on a soft plate so it stays readable.')),
                    ]),
                Section::make(__('Date and time'))
                    ->description(__('Dates are written in each person\'s own language.'))
                    ->schema([
                        Toggle::make('uses_24_hour_time')
                            ->label(__('Use 24-hour time'))
                            ->helperText(__('On: 14:30. Off: 2:30 PM.')),
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
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label(__('Save'))
                                ->submit('save'),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $church = $this->church();
        abort_unless($church, 403);

        $data = $this->form->getState();
        $previousLogo = $church->logo_path;
        $newLogo = $data['logo_path'] ?? null;

        if (is_array($newLogo)) {
            $newLogo = $newLogo[0] ?? null;
        }

        $church->update([
            'name' => (string) $data['name'],
            'logo_path' => filled($newLogo) ? (string) $newLogo : null,
            'uses_24_hour_time' => (bool) ($data['uses_24_hour_time'] ?? true),
        ]);

        if (filled($previousLogo) && $previousLogo !== $church->logo_path) {
            Storage::disk('public')->delete($previousLogo);
        }

        Notification::make()
            ->title(__('Church settings saved'))
            ->success()
            ->send();
    }

    protected function church(): ?Church
    {
        return app(CurrentChurch::class)->get() ?? auth()->user()?->church;
    }
}
