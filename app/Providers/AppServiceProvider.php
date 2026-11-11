<?php

namespace App\Providers;

use App\Http\Middleware\SetCurrentChurch;
use App\Notifications\ChurchResetPasswordNotification;
use App\Support\DateTimeFormats;
use Filament\Actions\DeleteBulkAction;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResetPassword::class, ChurchResetPasswordNotification::class);
    }

    public function boot(): void
    {
        // Filament create/edit submits via Livewire update routes, which skip
        // panel middleware — keep church (Spatie team) context on those requests.
        Livewire::addPersistentMiddleware([
            SetCurrentChurch::class,
        ]);

        // An upload field's existing path comes from the browser. Accept only the path already stored
        // on the record (or a fresh upload), so nobody can point a record at another church's file
        // and have it rewritten or deleted.
        FileUpload::configureUsing(function (FileUpload $component): void {
            $component->preventFilePathTampering();
        });

        // Filament authorizes bulk delete through deleteAny(), which no policy defines and which
        // therefore allows everyone who can see the list. Check each record's delete() instead.
        DeleteBulkAction::configureUsing(function (DeleteBulkAction $action): void {
            $action->authorizeIndividualRecords();
        });

        // Written dates follow the viewer's language; 12- or 24-hour time is the church's choice.
        Table::configureUsing(function (Table $table): void {
            $table
                ->defaultDateDisplayFormat(fn (): string => DateTimeFormats::date())
                ->defaultDateTimeDisplayFormat(fn (): string => DateTimeFormats::dateTime())
                ->defaultTimeDisplayFormat(fn (): string => DateTimeFormats::time());
        });

        Schema::configureUsing(function (Schema $schema): void {
            $schema
                ->defaultDateDisplayFormat(fn (): string => DateTimeFormats::date())
                ->defaultDateTimeDisplayFormat(fn (): string => DateTimeFormats::dateTime())
                ->defaultTimeDisplayFormat(fn (): string => DateTimeFormats::time());
        });

        // Edit fields stay numeric (Y-m-d) so they are quick to read and type in every language.
        DateTimePicker::configureUsing(function (DateTimePicker $picker): void {
            $picker
                ->defaultDateDisplayFormat(DateTimeFormats::INPUT_DATE)
                ->defaultDateTimeDisplayFormat(fn (): string => DateTimeFormats::inputDateTime())
                ->defaultTimeDisplayFormat(fn (): string => DateTimeFormats::time());
        });

        // The public demo must never send real email to testers, whatever MAIL_* says.
        // Mail only for the operators uses demo.operator_mailer instead.
        if (config('demo.enabled')) {
            config(['mail.default' => 'log']);
        }
    }
}
